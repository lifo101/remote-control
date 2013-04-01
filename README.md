# Remote Control

"Remote Control" is a PHP class library that allows you to programically control a remote device via its `CLI` interface _(usually via SSH or Telnet)_ or any other command via `STDIN` and `STDOUT` using [Expect][expect] in an easy to use object oriented manner.

I've based this class design on my own [Cisco Automation][cisco-automation] perl library here on github. However, I've tried to make this library as generic as possible to work with any any process or device.

## Development Notes

The PHP [Expect][expect] library is extremely limited and does not even come close to the perl Expect module available on `CPAN`. As of this writing the newest version of [php-expect][expect] is v0.3.1 _(updated almost 2 years ago!)_. Two main limitations is the way [php-expect][expect] handles capturing output and pattern matching.

* [php-expect][expect] supports an INI setting `expect.logfile` to capture output but due to buffering its of limited use in a _real-time_ program that wants to read/write to a process. In order to allow for _real-time_ output capturing I have to constantly set and reset the `expect.logfile` INI setting during runtime. This causes the the file to flush and the class then intelligently knows where to read from the file to determine output from a previous command. I haven't noticed any performance issues with this so far.
* [php-expect][expect] says it supports `REGEXP` style patterns but doesn't clearly state if that is truly PREG patterns or not. In my testing certain patterns cause Segfaults or simply do not work the way you're used to. Most notably is you cannot use modifiers _(multi-line: m)_ and because of that its a little harder to limit prompt matches, etc.

## Project Setup

### Dependencies 

1. PHP 5.3+
2. [Expect][expect] PECL extension

### Installation

[Composer][composer] is the recommended way to download and maintain your copy of the library (using [packagist.org](https://packagist.org/)).
Using [Github][git] directly is also a reasonable option, however, you'll have to manually create an autoloader for the classes or `include` them on your own (ugly!)

#### Composer Installation

1. Add "lifo/remote-control" to your project composer.json file:

    ```json
    {
        "require": {
            "lifo/remote-control": "dev-master"
        }
    }
    ```
    
2. Run composer update: `php composer.phar update lifo/remote-control`

#### GitHub Installatin

1. Clone the remote-control repository:

    `git clone git://github.com/lifo101/remote-control.git`

2. Add `Lifo/RemoteControl` to your project autoloader as-needed.

## Examples

See the [examples][examples] directory for more runnable examples.

```php
use Lifo\RemoteControl\RemoteControl;
use Lifo\RemoteControl\Type\NetworkDevice;

// Raw control object for low level access (see 2nd example for an easier approach)
$rc = new RemoteControl("ssh username@hostname", array(
    'auto_start' => true,
    'log_stdout' => true,
    // more options available
));

// generic prompt for "cisco" devices; your milage may vary.
$prompt = '[a-zA-Z0-9._-]+ ?(\(config[^\)]*\))? ?[$#>] ?(\(enable\))? *$';

// wait for output from the process and act upon various patterns.
// Each pattern can be a closure or simple variable. If any closure
// returns true or RemoteControl::WAIT_DONE then the wait loop will end.
$res = $rc->wait(array(
    //    REGEX/GLOB/STR => Closure or mixed value
    array($prompt        => true ),
    array('yes/no\)\?'   => function($rc){ $rc->writeln('yes'); } ),
    array('[Pp]assword:' => function($rc){ $rc->writeln('password'); } ),
));

// attempt another command and wait for prompt
$rc->writeln("show clock");
$rc->wait($prompt);

// ----------------------------------------------------------
// OR ... (this is preferred over the low-level method above)
// ----------------------------------------------------------

// High level control object for easier access of network type devices
$d = new NetworkDevice(array(
    'protocol' => 'ssh',
    'host'     => 'hostname',
    'username' => 'username',
    'password' => 'password',
    'enable'   => 'enablepw',
    // more options ...
    //'remote_control_options' => array(
    //    'log_stdout' => true,
    //)
));
$d->verbose(true); // for debugging/troubleshooting only; same as setting log_stdout = true

// login method handles a lot of variations for different devices and 
// will enable if possible. You can call $d->enable() manually too.
if (!$d->login()) {
    die("Error logging in!\n");
}

// basic method to send a command and wait for a prompt. Returns 
// any output received after the commmand.
echo $d->send("show version");
```



  [expect]: http://php.net/manual/en/book.expect.php "PHP Expect"
  [composer]: http://getcomposer.org/ "Composer"
  [git]: http://github.com/ "Github"
  [cisco-automation]: http://github.com/lifo101/cisco-automation "Perl library for connecting to Cisco devices via Expect"
  [examples]: examples
