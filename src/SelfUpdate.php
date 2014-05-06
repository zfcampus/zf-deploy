<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Deploy;

use Herrera\Phar\Update\Manager as UpdateManager;
use Herrera\Phar\Update\Manifest as UpdateManifest;
use KevinGH\Version\Version;
use Zend\Console\Adapter\AdapterInterface as Console;
use Zend\Console\ColorInterface as Color;
use ZF\Console\Route;

class SelfUpdate
{
    const MANIFEST_FILE = 'https://packages.zendframework.com/zf-deploy/manifest.json';

    /**
     * @var string
     */
    protected $version;

    /**
     * @param mixed $version
     */
    public function __construct($version)
    {
        $this->version = $version;
    }

    /**
     * Perform a self-update on the phar file
     *
     * @param Route $route
     * @param Console $console
     * @return int
     */
    public function __invoke(Route $route, Console $console)
    {
        $manifest = UpdateManifest::loadFile(self::MANIFEST_FILE);
        $manager  = new UpdateManager($manifest);

        if (! $manager->update($this->version, true, true)) {
            $console->writeLine('No updates available.', Color::YELLOW);
            return 0;
        }

        $version = new Version($this->version);
        $update = $manifest->findRecent($version);

        $console->write('Updated to version ');
        $console->writeLine($update->getVersion(), Color::GREEN);
        return 0;
    }
}
