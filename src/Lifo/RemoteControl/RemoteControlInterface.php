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

interface RemoteControlInterface
{
    public function start();

    public function end();

    public function done();

    public function before();

    public function wait($pattern, $options = array());

    public function write($str, $options = array());

    public function writeln($str, $options = array());

    public function setCommand($command);

    public function getStream();

    public function getOutput();
}
