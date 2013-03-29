<?php
/**
 * This snippet shows a "hello world" bare bones example of connecting to a
 * host and issuing some commands using the NetworkDevice remote control.
 *
 * @author Jason Morriss <lifo2013@gmail.com>
 * @since 1.0
 */

include __DIR__ . "/autoload.php";

use Lifo\RemoteControl\Type\NetworkDevice;

$username = 'username';
$password = 'password';

// login to the localhost via ssh ...
$d = new NetworkDevice(array(
    'protocol' => 'ssh',
    'host' => 'localhost',
    'username' => $username,    // username must be defined here
    // if no password is given here it must be passed to login() instead
    //'password' => $password,
));
$d->verbose(true);  // display all output to STDOUT for debugging

// always login first
$d->login($password);

// after login you can start interacting with the process ...

// issue some random commands ...
$out  = $d->send("date");
$out .= $d->send("df -h");
$out .= $d->send("uptime");
//echo $out; // if verbose is enabled this is not needed

// not really needed ...
$d->send("logout");
$d->disconnect();
