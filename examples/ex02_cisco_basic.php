<?php
/**
 * This snippet shows a basic example of connecting to a Cisco device and
 * issuing some commands using the CiscoDevice high level remote control.
 *
 * php ex02_cisco_basic.php 'hostname' 'password'
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */

include __DIR__ . "/autoload.php";

use Lifo\RemoteControl\Type\CiscoDevice;

$hostname = @$argv[1] ?: 'localhost';
$username = get_current_user();
$password = @$argv[2] ?: getenv('PASSWORD') ?: 'password';
$enable   = @$argv[3] ?: getenv('ENABLE') ?: $password;

// login to the device ...
$d = new CiscoDevice(array(
    'host' => $hostname,
    'username' => $username,    // username must be defined here
    // if no password is given here it must be passed to login() instead
    'password' => $password,
    'enable' => $enable,
));
$d->verbose(true);  // display all output to STDOUT for debugging

echo "Connecting to $username@$hostname ...\n";
// always login first
$d->login(); // if $password is specified when object is instantiated above
// or:
//$d->login($password, $enable);
// or:
// $d->login($password);
// $d->enable($enable);

// after login you can start interacting with the device ...

// issue some random commands ...
$out  = $d->send("sh ver");
$out .= $d->send("sh int desc");
$out .= $d->send("sh clock");
//echo $out; // if verbose is enabled this is not needed

// not really needed ...
$d->send("exit");
$d->disconnect();
