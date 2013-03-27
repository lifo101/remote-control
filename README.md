# Remote Control

"Remote Control" is a PHP class library that allows you to programically control a remote device via its `CLI` interface _(usually via SSH or Telnet)_ or any other command via `STDIN` and `STDOUT` using [Expect][expect] in an easy to use object oriented manner. * **WIP** *

## Project Setup

### Dependencies 

1. PHP 5.3+
2. [Expect][expect] PECL extension

### Installation

[Composer][composer] is the recommended way to download and maintain your copy of the library. Using [Github][git] directly is also a reasonable option, however, you'll have to manually download future updates.

#### Composer Installation

_placeholder_

## Examples

**WIP:** _API has not been fully fleshed out yet._

```php
use Lifo\RemoteControl\RemoteControl;
use Lifo\RemoteControl\Type\NetworkDevice;

// Raw control object for low level access
$rc = new RemoteControl(array(
    'command' => "ssh username@hostname",
));

// or ...

// High level control object for easier access of common devices
$rc = new NetworkDevice("hostname", "username", "password", "enable");
echo $rc->writeln("show version");

```



  [expect]: http://php.net/manual/en/book.expect.php "PHP Expect"
  [composer]: http://getcomposer.org/ "Composer"
  [git]: http://github.com/ "Github"
