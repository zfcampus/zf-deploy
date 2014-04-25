#!/usr/bin/env php
<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

use Zend\Console\Console;
use ZF\Console\Application;
use ZF\Console\Dispatcher;
use ZF\Deploy\SelfUpdate;

switch (true) {
    case (file_exists(__DIR__ . '/../vendor/autoload.php')):
        // Installed standalone
        require __DIR__ . '/../vendor/autoload.php';
        break;
    case (file_exists(__DIR__ . '/../../../autoload.php')):
        // Installed as a Composer dependency
        require __DIR__ . '/../../../autoload.php';
        break;
    case (file_exists('vendor/autoload.php')):
        // As a Composer dependency, relative to CWD
        require 'vendor/autoload.php';
        break;
    default:
        throw new RuntimeException('Unable to locate Composer autoloader; please run "composer install".');
}

define('VERSION', '0.3.0-dev');

$dispatcher  = new Dispatcher();
$dispatcher->map('self-update', new SelfUpdate(VERSION));
$dispatcher->map('build', 'ZF\Deploy\Deploy');

$application = new Application(
    'ZFDeploy',
    VERSION,
    include __DIR__ . '/../config/routes.php',
    Console::getInstance(),
    $dispatcher
);
$exit = $application->run();
exit($exit);
