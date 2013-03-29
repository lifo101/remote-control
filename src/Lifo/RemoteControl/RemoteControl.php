<?php
/**
 * This file is part of the Lifo\RemoteControl PHP Library.
 *
 * (c) Jason Morriss <lifo2013@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Lifo\RemoteControl;

use Lifo\RemoteControl\Exception\RemoteControlException;

/**
 * Main RemoteControl class
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */
class RemoteControl implements RemoteControlInterface
{
    const WAIT_DONE = -100;

    /** @var array Default options for newly created objects */
    public static $DEFAULT_OPTIONS = array(
        'auto_start'            => false,       // if true ->start is called in constructor
        'pattern_type'          => EXP_REGEXP,  // default pattern type for matches
        'timeout'               => 10,          // default timeout for all wait() calls
        'log_file'              => null,        // if null a temp file is created in system temp
        'log_stdout'            => false,       // output buffer to STDOUT after every match?
        'delete_log_file'       => true,        // if true the log file is deleted when end() is called
        'eol'                   => "\n",        // End of Line character for writeln()
        'clear_output_on_wait'  => true,        // clear output buffer on every wait?
        'wait_context'          => null,        // object to pass as $this to wait callbacks
    );

    /** @var Resource The expect stream resource */
    protected $stream;

    /** @var array Configuration options */
    protected $options;

    /** @var string Command to spawn */
    protected $command;

    /** @var string Content before the last matched pattern. */
    protected $before;

    /** @var boolean Used within wait() to signal waiting is done. */
    private $waitDone;

    private $logFile;
    private $logFilePos;

    /**
     * Public constructor
     *
     * @param string $command The command to spawn
     * @params array $options Optional set of options
     */
    public function __construct($command = null, $options = null)
    {
        $this->before = '';

        $this->options = array_merge(self::$DEFAULT_OPTIONS, ($options instanceof \ArrayAccess or is_array($options)) ? $options : array());

        if ($command !== null) {
            $this->setCommand($command);
        }

        //if ($this->command and $this->options['auto_start']) {
        if ($this->options['auto_start']) {
            $this->start();
        }

    }

    /**
     * Set the command to be spawned by Expect.
     *
     * If this is called after a previous command was started a call to {start}
     * must be made.
     *
     * @param string Command string to spawn.
     * @return RemoteControl
     */
    public function setCommand($command)
    {
        if (empty($command)) {
            self::toss('InvalidArgumentException', 'No "command" defined');
        }

        $this->command = $command;
        return $this;
    }

    /**
     * Start the expect command. If the command is already spawned it will be
     * ended first.
     *
     * @return RemoteControl
     */
    public function start()
    {
        if (!$this->command) {
            self::toss('RemoteControlException', 'No command defined');
        }

        if ($this->stream) {
            $this->end();
        }

        // don't want output going to STDOUT; stupid
        ini_set('expect.loguser', false);

        $this->logFile = $this->options['log_file'] ?: tempnam(sys_get_temp_dir(), 'EX_');
        ini_set('expect.logfile', $this->logFile);

        // start at the end of the file for log reporting
        clearstatcache(true, $this->logFile);
        $this->logFilePos = filesize($this->logFile);

        $this->stream = fopen('expect://' . $this->command, "r+");
        return $this;
    }

    /**
     * End the current expect command.
     *
     * @return RemoteControl
     */
    public function end()
    {
        if ($this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }

        //ini_set('expect.loguser', true);
        ini_set('expect.logfile', '');

        if ($this->logFile and $this->options['delete_log_file']) {
            @unlink($this->logFile);
            $this->logFile = null;
            $this->logFilePos = 0;
        }

        return $this;
    }

    /**
     * Wait for 1 or more patterns to match.
     *
     * @param $pattern regex Pattern to wait for
     * @return mixed Returns the return code of the last pattern closure or
     *               false on TIMEOUT or EOF.
     */
    public function wait($patterns, $options = array())
    {
        $options = array_merge($this->options, ($options instanceof \ArrayAccess or is_array($options)) ? $options : array());

        // apply options ...
        if ($options['timeout'] !== null) {
            $this->setTimeout($options['timeout']);
        }
        if ($options['clear_output_on_wait']) {
            $this->output = '';
        }
        $context = $options['wait_context'] ?: $this;

        list($cases, $callbacks) = $this->compilePatterns($patterns);

        $this->waitDone = false;
        while (true) {
            $matches = array();
            $got = expect_expectl($this->stream, $cases, $matches);
            $this->matches = $matches;

            $this->before = $this->captureBefore(); // capture the last matched output
            $this->output .= $this->before;         // capture combined output

            // @todo is this actually useful since the user can enable the ini
            // expect.loguser? This allows it to be real-time and unbuffered.
            if ($options['log_stdout']) {
                echo $this->before;
            }

            if (isset($callbacks[$got])) {
                $res = call_user_func_array($callbacks[$got], array( $context ));
                if ($res === true or $res === self::WAIT_DONE or $this->waitDone) {
                    return $res ?: self::WAIT_DONE;
                }
            } else {
                if ($got === EXP_TIMEOUT or $got === EXP_EOF) {
                    return $got;
                } else {
                    // @todo What is the best way to handle unhandled matches?
                    self::toss('RemoteControlException', "Unhandled match " . (isset($cases[$got]) ? '"' . $cases[$got][0] . '"' : '') . " ($got)");
                }
            }

        }

        // if we fall-thru to here then we had to of matched something in order
        // to break out of the loop above.
        return true;
    }

    /**
     * Shortcut for returning RemoteControl::WAIT_DONE in callback functions.
     *
     * @return boolean Always returns true
     */
    public function done()
    {
        $this->waitDone = true;
        return self::WAIT_DONE;
    }

    /**
     * Get the current output buffer up to the last match performed.
     *
     * @return string All content before the last pattern match.
     */
    public function before()
    {
        return $this->before;
    }

    /**
     * Write a string to the Expect process.
     *
     * @param string $str The string to write.
     * @param array $options Optional options; Not currently used
     */
    public function write($str, $options = array())
    {
        fwrite($this->stream, $str);
        return $this;
    }

    /**
     * Write a string to the Expect process. A newline is always appended.
     *
     * @param string $str The string to write.
     * @param array $options Optional options; Not currently used
     */
    public function writeln($str, $options = array())
    {
        return $this->write($str . (isset($options['eol']) ? $options['eol'] : $this->options['eol']), $options);
    }

    /**
     * Captures the "before" content.
     *
     * This is a hack to allow a program to capture the current expect output
     * w/o buffering issues due to the php-expect library limitations.
     *
     * @return string Returns the output buffer
     */
    public function captureBefore()
    {
        $prev = ini_get('expect.logfile');
        if ($prev != '') {
            ini_set('expect.logfile', '');      // flushes the buffer

            $before = file_get_contents($prev, false, null, $this->logFilePos ?: 0);
            if ($this->logFilePos !== null) {
                $this->logFilePos += strlen($before);
            } else {
                $this->logFilePos = strlen($before);
            }

            ini_set('expect.logfile', $prev);
            return $before;
        }
        return '';
    }

    /**
     * Clear the output buffer
     *
     * @return Object RemoteControl
     */
    public function clearOutput()
    {
        $this->output = '';
        return $this;
    }

    /**
     * Return all output collected so far.
     *
     * @return string String output.
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Get the current Expect stream resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Get the matches found from the last wait() call.
     *
     * @return array A list of regex match patterns
     */
    public function getMatches()
    {
        return $this->matches;
    }

    /**
     * Converts the pattern given into an array of cases suitable for
     * expect_expectl()
     *
     * @param string|array $patterns Pattern(s) to convert
     * @return array A two element array of "cases" and "callbacks"
     */
    protected function compilePatterns($patterns)
    {
        // Treat a single string as a wait and return pattern.
        if (is_string($patterns) or is_int($patterns)) {
            return $this->compilePatterns( array($patterns => true) );
        }

        $cases = array();
        $funcs = array();
        foreach ($patterns as $key => $pat) {
            $i = count($funcs);

            // the first element is a regex => closure/action
            if (!is_array($pat)) {
                $pat = array($key => $pat );
            }

            list($regex, $action) = each($pat);

            // second element is an optional EXP_* type
            list(, $type) = each($pat);

            // all actions must be a valid callable/Closure
            if (!is_callable($action)) {
                $_act = $action;
                $action = function($rc) use ($_act) { return $_act; };
            }

            // do not add EXP_TIMEOUT and EXP_EOF to $cases array;
            // expect_expectl doesn't like it!
            if (!in_array($regex, array(EXP_EOF, EXP_TIMEOUT), true)) {
                $funcs[$i] = $action;
                $cases[$i] = array( $regex, $i, empty($type) ? $this->options['pattern_type'] : $type );
            } else {
                $funcs[$regex] = $action;
            }
        }

        return array($cases, $funcs);
    }

    /**
     * Reset the current capture log.
     */
    public function resetLog()
    {
        if (ini_get('expect.logfile')) {
            ini_set('expect.logfile', ini_set('expect.logfile', ''));
        }
        return $this;
    }

    /**
     * Get current options
     *
     * @return array Current options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set new options
     *
     * Defaults are merged with the new set of options so any missing values
     * are set to their defaults.
     *
     * @param array $options New options.
     */
    public function setOptions($options)
    {
        $this->options = array_merge(self::$DEFAULT_OPTIONS, (array)$options);
        return $this;
    }

    /**
     * Set the expect.timeout to a new value.
     *
     * @param integer $timeout New timeout value.
     * @return integer Previous timeout value.
     */
    public static function setTimeout($timeout)
    {
        return ini_set('expect.timeout', $timeout);
    }

    /**
     * Get the current timeout value.
     */
    public static function getTimeout()
    {
        return ini_get('expect.timeout');
    }

    /**
     * Throw an expection that includes the file and line of the caller.
     *
     * @param string $class Exception class to throw.
     * @param string $message Error message.
     */
    private static function toss($class, $message)
    {
        $trace = debug_backtrace(false);
        $caller = $trace[0];
        foreach ($trace as $t) {
            if ($t['file'] != __FILE__) {
                $caller = $t;
                break;
            }
        }

        throw new $class(sprintf("%s at %s:%d", $message, $caller['file'], $caller['line']));
    }

}
