<?php
/**
 * This file is part of the Lifo\RemoteControl PHP Library.
 *
 * (c) Jason Morriss <lifo2013@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Lifo\RemoteControl\Exception;

/**
 * RemoteControl Exception
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */
class RemoteControlException extends \Exception
{
    protected $output;

    public function setOutput($output)
    {
        $this->output = $output;
        return $this;
    }

    public function getOutput()
    {
        return $this->output;
    }
}
