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
        parent::__construct($options);
        $this->options['prompt'] = '[a-zA-Z0-9._-]+ ?(\(config[^\)]*\))? ?[$#>] ?(\(enable\))? *$';
    }

    // @todo Implement cisco specific features like:
    //       reload(), copy(), dir(), etc...
}
