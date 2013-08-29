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
            // Should output be normalized? (remove "More" prompts and fix backspaces, etc)
            'normalize_output' => true,
        ), $options);
        parent::__construct($options);
    }

    /**
     * Get the current output buffer.
     *
     * Try to reassemble text by intelligently removing backspaces from output.
     * This happens on Cisco devices when user input is echoed. If a long line
     * of text is entered by the 'user' then the Cisco scrolls the prompt by
     * using a lot of extra backspaces and spaces. This looks ugly in the
     * captured output so this tries to remove the extra garbage w/o affecting
     * the actual command text.
     *
     * This also removes "More" prompts.
     *
     * @param array|boolean $options Array of options or a boolean for the
     *                               option "normalize_output".
     */
    public function getOutput($options = array())
    {
        $output = parent::getOutput();

        $options = array_merge($this->options, is_array($options) ? $options : array('normalize_output' => $options));

        if ($output !== null and $options['normalize_output']) {
            // Remove "More" prompts
            $output = str_replace("\r", "", preg_replace(array(
                '/<--- More --->\r\s{14}\r/',       // cisco firewalls
                '/ --More-- \x08{9}\s{8}\x08{9}/',  // cisco routers
            ), '', $output));

            $str = '';
            foreach (explode("\n", $output) as $line) {
                // short-cut; no "^H" (backspaces) found so just continue
                if (strpos($line, "\x08") == -1) {
                    $str .= $line . "\n";
                    continue;
                }

                $parts = array();
                $idx = -1;
                $bs_length = 0;
                $bs_length_first = 0;
                $forward = '';

                // loop through each character and act accordingly ...
                $state = 'NORMAL';
                foreach (str_split($line) as $c) {
                    $idx ++;
                    // The order of the case statements below are crucial!
                    switch ($state) {
                        // In NORMAL state we simply eat characters until we
                        // see a ^H (backspace) character.
                        case 'NORMAL':
                            if ($c !== "\x08") {
                                $str .= $c;
                                break;
                            } else {
                                $parts[] = $str;
                                $str = '';
                                $state = 'REVERSE1';
                                // fallthru to REVERSE1
                            }

                        // REVERSE1 state occurs after NORMAL and we eat ^H
                        // characters until we hit a normal character.
                        case 'REVERSE1':
                            if ($c === "\x08") {
                                break;
                            } else {
                                $forward = '';
                                $state = 'FORWARD';
                                // fallthru to FORWARD
                            }

                        // FORWARD state occurs after REVERSE1 and eats chars
                        // until a ^H is seen.
                        case 'FORWARD':
                            if ($c !== "\x08") {
                                $forward .= $c;
                                break;
                            } else {
                                $state = 'REVERSE2';
                                // fallthru to REVERSE2
                            }

                        // REVERSE2 state occurs after FORWARD and eats ^H
                        // until we hit a normal character.
                        case 'REVERSE2':
                            if ($c === "\x08") {
                                $bs_length ++;
                            } else {
                                if (!$bs_length_first) {
                                    $bs_length_first = $bs_length;
                                }

                                // get the last character
                                $str .= substr($forward, -$bs_length_first-1, 1);

                                // when the ^H lengths are not the same it tells
                                // us we're at the end of the user text and we
                                // can ignore the remaining input on the line.
                                if ($bs_length_first != $bs_length) {
                                    $parts[] = $str;
                                    $str = '';
                                    break 2; // break out of switch and foreach
                                }

                                $str .= $c;
                                $bs_length = 0;
                                $state = 'NORMAL';
                            }
                            break;

                        default: // failsafe against regression bugs; should never occur
                            throw new \Exception("Unknown state ($state) for text normalization.");
                    } # switch

                } # foreach char

                $str .= implode('', $parts) . "\n";
            } # foreach line

            return $str;
        }
        return $output;
    }

    // @todo Implement cisco specific features like:
    //       reload(), copy(), dir(), etc...
}
