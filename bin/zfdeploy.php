#!/usr/bin/env php
<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

use Zend\Console\Console;
use ZF\Console\Application;
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

define('VERSION', '1.0.3-dev');

$routes      = include __DIR__ . '/../config/routes.php';
$application = new Application(
    'ZFDeploy',
    VERSION,
    $routes,
    Console::getInstance()
);
$application->getDispatcher()->map('self-update', new SelfUpdate(VERSION));

$exit = $application->run();
exit($exit);
