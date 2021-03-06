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
    const BACKSPACE = "\x08";
    const CTRL_Z    = "\x1A";

    const LOGIN_FAIL = 0;
    const LOGIN_USER = 1;
    const LOGIN_EXEC = 2;

    public static $DEFAULT_OPTIONS = array(
        'prompt'                    => '[#$] *$',
        'remote_control_class'      => null,
        'remote_control_options'    => array(),
        'protocol'                  => 'ssh',
        'cmdline'                   => null,    // extra command line arguments
        'port'                      => null,
        'username'                  => null,
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
            return $this->userLevel;
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
        $attempt = 0;

        $res = $this->remote->wait(array(
            array('Connection refused' => true ),
            array('yes/no\)\?' => function($rc) { $rc->writeln('yes'); }),
            array('Host key verification failed' => true ),         // level=0
            array('Permission denied' => true ),                    // level=0
            array('Access denied' => true ),                        // level=1
            array('[Pp]assword:' => function($rc) use (&$pw, &$attempt) {
                $attempt++;
                $rc->writeln($pw);
            }),
            array($options['prompt'] => function($rc) use (&$level, &$pw, &$attempt, &$enable) {
                $level++;
                if ($enable !== null and $level == 1) {
                    $rc->writeln("enable");
                    $pw = $enable;
                    $attempt = 0;
                    return; // continue
                }
                return true; // done; logged in
            }),
        ));

        // level: 0=failed, 1=logged in, 2=enabled
        $this->userLevel = $level;
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
        $this->writeln('enable');
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
        if (!isset($options['wait'])) {
            $options['wait'] = true;
        }

        $this->writeln($cmd, $options);
        if (!$options['wait']) {
            return;
        }

        // extra patterns from caller
        $patterns = isset($options['patterns']) ? $options['patterns'] : array();

        $patterns = array_merge($patterns, array(
            array('<--- More ---> *$' => function($rc){ $rc->write(" "); }),
            array(' +--More-- *$' => function($rc){ $rc->write(" "); }),
            array($options['prompt'] => true),
        ));

        $this->lastWaitStatus = $this->remote->wait($patterns, $options);

        return $this->getOutput();
    }

    /**
     * Sends 1 or more lines to the device and returns all output captured after
     * all lines have been sent (if wait_for_output is true). Special options
     * are available to throttle the speed at which lines are sent to prevent
     * overrunning the device with a long list of lines.
     * This is useful for sending configurations.
     *
     * Use "max_lines" in the $options array to limit how many lines are sent
     * before waiting for a prompt. Defaults to 1. Increasing this will speed
     * up large batches of lines since Expect won't try and wait after every
     * single line sent. But note; If you want to capture the output after
     * calling this it's possible for some output to be missed if you don't
     * properly wait afterwards since this function will return quicker then
     * the remote device can send its output.
     *
     * @param mixed $lines An array or string of lines to send. If a string is
     *                     given it will be split on CRLF
     * @param array$options Optional options array
     */
    public function sendLines($lines, $options = array())
    {
        if ($lines === null) {
            return;
        }

        if (!is_array($lines)) {
            $lines = preg_split('/\r?\n/', $lines);
        }

        $options = array_merge($this->options, (array)$options);
        if (!isset($options['wait_for_output'])) {
            $options['wait_for_output'] = true;
        }
        if (!isset($options['max_lines'])) {
            $options['max_lines'] = 1;
        }

        // never clear on wait so we can get the full output after all lines are sent.
        $options['clear_output_on_wait'] = false;

        $output = '';
        $cnt = 0;
        $waitFor = 0;
        while (!empty($lines)) {
            $cmd = array_shift($lines);
            if (++$cnt > $options['max_lines']) {
                $cnt = 1;
            }
            $waitFor++;

            //$options['wait'] = (empty($lines) or ($options['max_lines'] and $cnt >= $options['max_lines']));
            $options['wait'] = ($options['max_lines'] and $cnt >= $options['max_lines']);
            if ($options['wait']) {
                $waitFor--;
            }
            $this->send($cmd, $options);
            if (in_array($this->lastWaitStatus, array(EXP_TIMEOUT, EXP_EOF), true)) {
                break;
            }
        }

        if ($options['wait_for_output']) {
            // @todo This doesn't really help all that much and isn't fullproof.
            //       Refactor this or get rid of it completely.

            // if we didn't wait for each line in the loop above then we need to
            // wait for JUST the prompt to return or the output returned won't
            // necessarily be complete yet because the remote end hasn't sent
            // everything yet.
            if ($waitFor > 0) {
                $res = $this->remote->wait(array(
                    array($options['prompt'] . '$' => true),
                ));
            }

            return $this->getOutput();
        }
    }

    public function writeln($str, $options = array())
    {
        if ($str == self::CTRL_Z) {
            $options['eol'] = '';
        }
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
        $old = empty($this->options['remote_control_options']['log_stdout']) ? null : $this->options['remote_control_options']['log_stdout'];
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

        if (empty($options['host'])) {
            throw RemoteControlException("Unable to build command: No \"host\" defined.");
        }

        $method = 'build' . strtoupper($options['protocol'] ?: 'ssh') . 'Command';
        $cmd = self::$method($options['host'], $options['username'], $options);

        return $cmd;
    }

    protected static function buildSSHCommand($host, $username, array $options)
    {
        $cmd = $options['protocol'] ?: 'ssh';
        if (!empty($options['port']) and $options['port'] != '22') {
            $cmd .= " -p " . $options['port'];
        }

        if ($username !== null and $username !== false and $username !== '') {
            $cmd .= " -l $username";
        }

        if (!empty($options['cmdline'])) {
            $cmd .= " " . $options['cmdline'];
        }

        $cmd .= " " . $host;

        return escapeshellcmd($cmd);
    }

    protected static function buildTELNETCommand($host, $username, array $options)
    {
        $cmd = $options['protocol'] ?: 'telnet';

        if (!empty($options['cmdline'])) {
            $cmd .= " " . $options['cmdline'];
        }

        if ($username !== null and $username !== false and $username !== '') {
            $cmd .= " -l $username";
        }

        $cmd .= " " . $host;

        if (!empty($options['port']) and $options['port'] != '23') {
            $cmd .= " " . $options['port'];
        }

        return escapeshellcmd($cmd);
    }

    public function getRemote()
    {
        return $this->remote;
    }

    public function getOutput()
    {
        return $this->remote->getOutput();
    }

    public function getUserLevel()
    {
        return $this->userLevel;
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
        $this->options[$name] = $value;
        return $this;
    }

    public function setOptions(array $options)
    {
        $this->options = array_merge(self::$DEFAULT_OPTIONS, $options);
        return $this;
    }

    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get an option.
     *
     * @param string $name Name of option to return.
     * @return mixed Returns the option value or null if it doesn't exist.
     */
    public function getOption($name)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }
        return null;
    }

}
