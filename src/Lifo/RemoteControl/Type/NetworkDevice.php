<?php
/**
 * This file is part of the Lifo\RemoteControl PHP Library.
 *
 * (c) Jason Morriss <lifo2013@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Lifo\RemoteControl\Type;

use Lifo\RemoteControl\RemoteControl;
use Lifo\RemoteControl\RemoteControlInterface;
use Lifo\RemoteControl\Exception\RemoteControlException;

/**
 * Remote control for networked devices (eg: router, switches, etc...).
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */
class NetworkDevice
{
    const LOGIN_FAIL = 0;
    const LOGIN_USER = 1;
    const LOGIN_EXEC = 2;

    public static $DEFAULT_OPTIONS = array(
        'prompt'                 => '[a-zA-Z0-9._-]+ ?(\(config[^\)]*\))? ?[$#>] ?(\(enable\))? *$',
        'remote_control_class'   => null,
        'remote_control_options' => null,
        'protocol'               => 'ssh',
        'cmdline'                => null,        // extra command line arguments
        'port'                   => null,
    );

    protected $options;

    /** @var RemoteControl remote object */
    protected $remote;

    /** @var integer Current authorized level: LOGIN_[FAIL, USER, EXEC] */
    protected $userLevel;

    public function __construct($options = array())
    {
        $this->options = array_merge(self::$DEFAULT_OPTIONS, ($options instanceof \ArrayAccess or is_array($options)) ? $options : array());
        $this->userLevel = 0;
    }

    /**
     * Connect to the device.
     *
     * Does nothing if already connected.
     * Spawns the command needed to actually connect to the device. No other
     * interaction is performed.
     *
     * @param string $hostname Host to connect to
     * @param string $username Username
     * @param string $cmdline  Extra command line options
     */
    public function connect($host = null, $username = null, $options = null)
    {
        if ($this->remote) {
            return;
        }

        $options = array_merge($this->options, (array)$options);

        // build the command to spawn
        $this->command = self::buildCommand($options);

        // allow the caller to specify its own class for the RemoteControl
        $class = $options['remote_control_class'] ?: 'Lifo\\RemoteControl\\RemoteControl';
        if (is_object($class)) {
            $class = get_class($class);
        }

        // verify the class is valid and instantiate it
        if (class_exists($class)) {
            $rc = new $class($this->command, (array)$options['remote_control_options']);
            if (!($rc instanceof RemoteControlInterface)) {
                throw new RemoteControlException("Class \"$class\" does not implement \"RemoteControlInterface\"");
            }
        } else {
            throw new RemoteControlException("Class \"$class\" does not exist!");
        }

        $rc->start();
        $this->remote = $rc;

        return $this;
    }

    /**
     * Disconnect from the current RemoteControl session.
     *
     * Does nothing if not connected.
     */
    public function disconnect()
    {
        if ($this->remote) {
            $this->remote->end();
            $this->remote = null;
        }
        $this->userLevel = 0;
        return $this;
    }

    /**
     * Login to device and optionally enable.
     *
     * Several known connection scenarios are accounted for (like unknown ssh
     * keys, etc).
     *
     * @param string $password Password
     * @param string $enable   Enable
     * @param array  $options  Optional options
     * @return integer One of the LOGIN_* constants; 0 on failure
     */
    public function login($password = null, $enable = null, $options = array())
    {
        $this->connect();

        $options = array_merge($this->options, (array)$options);

        // do nothing if the user is already logged in
        if ($this->userLevel > self::LOGIN_FAIL) {
            return;
        }

        if ($password === null) {
            if (isset($options['password'])) {
                $password = $options['password'];
            } else {
                throw new RemoteControlException("No password defined for login.");
            }
        }

        if ($enable === null and isset($options['enable'])) {
            $enable = $options['enable'];
        }

        $level = 0;
        $pw = $password;

        $res = $this->remote->wait(array(
            array('Connection refused' => true ),
            array('yes/no\)\?' => function($rc) { $rc->writeln('yes'); }),
            array('Host key verification failed' => true ),
            array('Permission denied' => true ),
            array('[Pp]assword:' => function($rc) use (&$level, &$pw) {
                $rc->writeln($pw);
            }),
            array($options['prompt'] => function($rc) use (&$level, &$pw, $enable) {
                $level++;
                if ($enable !== null and $level == 1) {
                    $rc->writeln("enable");
                    $pw = $enable;
                    return; // continue
                }
                return true; // done; logged in
            }),
        ));

        // level: 0=failed, 1=logged in, 2=enabled
        $this->level = $level;
        return $level;
    }

    /**
     * Attempt to enable on the device.
     *
     * @param string $enable  Enable password
     * @param array  $options Optional options
     */
    public function enable($enable, $options = array())
    {
        $this->connect();

        $options = array_merge($this->options, (array)$options);

        //if ($this->userLevel > self::LOGIN_EXEC) {
        //    return;
        //}

        $ok = false;
        $attempt = 0;
        $this->remote->writeln('enable');
        $res = $this->remote->wait(array(
            array('Permission denied' => true ), // bad password
            array('ERROR: %' => true ), // already enabled so command fails (cisco)
            array('[Pp]assword:' => function($rc) use (&$attempt, $enable) {
                $attempt++;
                // no reason to do it a second or third time ...
                if ($attempt > 1) {
                    return true;
                }
                $rc->writeln($enable);
            }),
            array($options['prompt'] => function($rc) use (&$ok) {
                $ok = true;
                return true;
            }),
        ));

        return $ok;
    }

    /**
     * Send a command and wait for a prompt. Attempts to catch "more" prompts.
     *
     * Newline is auto-appended.
     *
     * @param string $cmd Command string to send
     * @param array $options Optional options
     * @return string Return output buffer
     */
    public function send($cmd, $options = array())
    {
        $this->connect();

        $options = array_merge($this->options, (array)$options);

        $wait = isset($options['no_wait']) ? !$options['no_wait'] : true;

        $this->remote->writeln($cmd, $options);
        if (!$wait) {
            return;
        }

        // extra patterns from caller
        $patterns = isset($options['patterns']) ? $options['patterns'] : array();

        $patterns = array_merge(array(
            array('<--- More ---> *$' => function($rc){ $rc->write(" "); }),
            array(' +--More-- *$' => function($rc){ $rc->write(" "); }),
            array($options['prompt'] => true),
        ), $patterns);

        $this->lastWaitStatus = $this->remote->wait($patterns, $options);

        return $this->remote->getOutput();
    }

    public function writeln($str, $options = array())
    {
        return $this->remote->writeln($str, $options);
    }

    public function write($str, $options = array())
    {
        return $this->remote->write($str, $options);
    }

    /**
     * shortcut to toggle STDOUT logging
     *
     * @param boolean $value New verbose setting
     * @return boolean Previous setting
     */
    public function verbose($value)
    {
        $old = $this->options['remote_control_options']['log_stdout'];
        $this->options['remote_control_options']['log_stdout'] = $value;
        if ($this->remote) {
            $options = $this->remote->getOptions();
            $old = $options['log_stdout'];
            $options['log_stdout'] = $value;
            $this->remote->setOptions($options);
        }
        return $old;
    }

    public static function buildCommand(array $options)
    {
        $options = array_merge(self::$DEFAULT_OPTIONS, $options);

        if (!isset($options['host'])) {
            throw RemoteControlException("Unable to build command: No \"host\" defined.");
        }

        $method = 'build' . strtoupper($options['protocol'] ?: 'ssh') . 'Command';
        $cmd = self::$method($options['host'], $options['username'], $options);

        return $cmd;
    }

    protected static function buildSSHCommand($host, $username, array $options)
    {
        $cmd = $options['protocol'] ?: 'ssh';
        if (isset($options['cmdline'])) {
            $cmd .= " " . $options['cmdline'];
        }
        if (isset($options['port']) and $options['port'] != '22') {
            $cmd .= " -p " . $options['port'];
        }
        $cmd .= " $username@$host";
        return escapeshellcmd($cmd);
    }

    protected static function buildTELNETCommand($host, $username, array $options)
    {
        $cmd = $options['protocol'] ?: 'telnet';
        if (isset($options['cmdline'])) {
            $cmd .= " " . $options['cmdline'];
        }
        $cmd .= " -l $username $host";
        if (isset($options['port'])) {
            $cmd .= " " . $options['port'];
        }
        return escapeshellcmd($cmd);
    }

    public function getRemote()
    {
        return $this->remote;
    }

    /**
     * Set a configuration option.
     *
     * @param string $name Option name.
     * @param mixed $value Option value.
     * @return NetworkDevice
     */
    public function setOption($name, $value)
    {
        if (array_key_exists($name, $this->options)) {
            $this->options[$name] = $value;
        }
        return $this;
    }

    public function setOptions(array $options)
    {
        $this->options = array_merge(self::$DEFAULT_OPTIONS, $options);
        return $this;
    }

}
