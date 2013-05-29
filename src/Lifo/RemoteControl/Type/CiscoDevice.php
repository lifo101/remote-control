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
 * Remote control for Cisco devices (eg: router, switches, etc...).
 *
 * Provides some overrides and features that are specific to Cisco devices.
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */
class CiscoDevice extends NetworkDevice
{
    public function __construct($options = array())
    {
        $options = array_merge(array(
            'prompt' => '[a-zA-Z0-9._-]+ ?(\(config[^\)]*\))? ?[$#>] ?(\(enable\))? *$',
        ), $options);
        parent::__construct($options);
    }

    /**
     * Get the current output buffer.
     *
     * Try to reassemble text by intelligently removing backspaces from output.
     */
    public function getOutput()
    {
        $output = parent::getOutput();
        if ($output !== null) {
            $output = preg_replace(array(
                '/\s{7}\x08+/',             // remove "       ^H^H^H^H^H^H^H^H" (from --More-- prompts)
                '/\r\s{14}\r/',             // remove "              " (from <--- More ---> prompts)
                '/\r/',                     // remove CR
            ), '', $output);

            // Only works for strings that only "loop" once on the cisco CLI....
            // I'm working on it...  ugh, this is annoying.
            $removeBackspaces = function($in) use (&$removeBackspaces) {
                $out = preg_replace_callback('/^([^\x08]+)(\x08+)\$.(.)\s+([^\x08]+)?\x08+(.+)/', function($m) use (&$removeBackspaces) {
                    $s = $m[1] . $m[3];
                    if (!empty($m[4]) and substr($m[4], 0, 1) != "\x08") {
                        $s .= $m[4];
                    } else {
                        $s .= $removeBackspaces($m[5]);
                    }
                    return $s;
                }, $in);
                return $out;
            };
            $output = implode("\n", array_map($removeBackspaces, explode("\n", $output)));
        }
        return $output;
    }

    // @todo Implement cisco specific features like:
    //       reload(), copy(), dir(), etc...
}
