<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Deploy;

use DOMDocument;
use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Zend\Console\Adapter\AdapterInterface as Console;
use Zend\Console\ColorInterface as Color;
use ZF\Console\Route;
use ZipArchive;

class Deploy
{
    /**
     * @var Console
     */
    protected $console;

    /**
     * Path to a downloaded composer.phar, if any
     *
     * @var null|string
     */
    protected $downloadedComposer;

    /**
     * Valid package file extensions
     *
     * @var array
     */
    protected static $validExtensions = array(
        'zip',
        'tar',
        'tar.gz',
        'tgz',
        'zpk',
    );

    /**
     * Retrieve list of allowed extensions
     *
     * @return array
     */
    public static function getValidExtensions()
    {
        return static::$validExtensions;
    }

    /**
     * Perform all operations
     *
     * Facade method that accepts incoming CLI arguments, parses them, and
     * determines what workflows to execute.
     *
     * @param Route $route
     * @param Console $console
     * @return int Exit status
     */
    public function __invoke(Route $route, Console $console)
    {
        $this->resetStateForExecution($console);

        $opts = (object) $route->getMatches();

        if (! $this->validatePackage($opts->package, $opts)) {
            return 1;
        }

        if (! $this->validateApplicationPath($opts->target, $opts)) {
            return 1;
        }

        if (! $this->validateModules($opts->modules, $opts->target)) {
            return 1;
        }

        $console->writeLine(sprintf('Creating package "%s"...', $opts->package), Color::BLUE);

        if (false === ($tmpDir = $this->createTmpDir())) {
            return 1;
        }

        if (false === ($tmpDir = $this->prepareZpk(
            $tmpDir,
            basename($opts->package, '.' . $opts->format),
            $opts->version,
            $opts->format,
            $opts->deploymentxml,
            $opts->zpkdata,
            $opts->appConfig))
        ) {
            return 1;
        }

        $this->cloneApplication($opts->target, $tmpDir, $opts->gitignore, $opts->vendor, $opts->modules, $opts->configs);
        $this->copyModules($opts->modules, $opts->target, $tmpDir);

        if (false === $this->executeComposer($opts->vendor, $opts->composer, $tmpDir)) {
            return 1;
        }

        $this->removeTestDir($tmpDir . '/vendor');

        if (false === $this->createPackage($opts->package, $tmpDir, $opts->format)) {
            return 1;
        }

        self::recursiveDelete($opts->format === 'zpk' ? dirname($tmpDir) : $tmpDir);

        $this->console->writeLine(sprintf(
            '[DONE] Package %s successfully created (%d bytes)',
            $opts->package,
            filesize($opts->package)
        ), Color::GREEN);

        return 0;
    }

    /**
     * Report an error
     *
     * Allows passing in a specific color to use when emitting the error
     * message; defaults to red.
     *
     * @param string $message
     * @param string $color
     * @return false
     */
    protected function reportError($message, $color = Color::RED)
    {
        $this->console->writeLine($message, $color);
        return false;
    }

    /**
     * Validate a deployment XML file against a schema
     *
     * @param string $file
     * @param string $schema
     * @return bool
     */
    protected function validateXml($file, $schema)
    {
        if (! file_exists($file)) {
            return $this->reportError(sprintf('The XML file "%s" does not exist.', $file));
        }
        if (! file_exists($schema)) {
            return $this->reportError(sprintf('Error: The XML schema file "%s" does not exist.', $schema));
        }

        // Validate the deployment XML file
        $dom = new DOMDocument();
        $dom->loadXML(file_get_contents($file));
        if (! $dom->schemaValidate($schema)) {
            return $this->reportError(sprintf('The XML file "%s" does not validate against the schema "%s".', $file, $schema));
        }

        return true;
    }

    /**
     * Validate the package file argument
     *
     * Determines the format, and, if the package file is valid, sets the
     * format for this invocation.
     *
     * @param string $package
     * @param object $opts All options
     * @return bool
     */
    protected function validatePackage($package, $opts)
    {
        // Does the file already exist? (if so, error!)
        if (file_exists($package)) {
            return $this->reportError(sprintf('Error: package file "%s" already exists', $package));
        }

        preg_match('#\.(?P<format>tar.gz|tar|tgz|zip|zpk)$#', $package, $matches);
        $format = $matches['format'];

        // Do we have the PHP extension necessary for the file format? (if not, error!)
        switch ($format) {
            case 'zip':
            case 'zpk':
                if (! extension_loaded('zip')) {
                    return $this->reportError('Error: the ZIP extension of PHP is not loaded.');
                }
                break;

            case 'tar':
            case 'tar.gz':
            case 'tgz':
                if (! class_exists('PharData')) {
                    return $this->reportError('Error: the Phar extension of PHP is not loaded.');
                }
                break;
        }

        $opts->format = $format;
        return true;
    }

    /**
     * Validate the application path
     *
     * If valid, also sets the $appConfig property in $opts.
     *
     * @param string $target
     * @param object $opts All options
     * @return bool
     */
    protected function validateApplicationPath($target, $opts)
    {
        // Is it a directory? (if not, error!)
        if (! is_dir($target)) {
            return $this->reportError(sprintf('Error: the application path "%s" is not valid', $target));
        }

        // Is it a valid ZF2 app? (if not, error!)
        $appConfigPath = $target . '/config/application.config.php';
        if (! file_exists($appConfigPath)) {
            return $this->reportError(sprintf('Error: the folder "%s" does not contain a standard ZF2 application', $target));
        }

        $appConfig = include $appConfigPath;
        if (! $appConfig || !isset($appConfig['modules'])) {
            return $this->reportError(sprintf('Error: the folder "%s" does not contain a standard ZF2 application', $target));
        }

        // Set $this->appConfig when done
        $opts->appConfig = $appConfig;
        return true;
    }

    /**
     * Validate the modules list
     *
     * @param array $modules
     * @param string $target
     * @return bool
     */
    protected function validateModules(array $modules, $target)
    {
        // If empty, done
        if (empty($modules)) {
            return true;
        }

        // Validate each module
        foreach ($modules as $module) {
            $normalized = str_replace('\\','/', $module);
            if (! is_dir($target . '/module/' . $normalized)) {
                return $this->reportError(sprintf('Error: the module "%s" does not exist in %s', $module, $target));
            }
        }

        return true;
    }

    /**
     * Validate a ZPK data directory
     *
     * @param string $dir
     * @return bool
     */
    protected function validateZpkDataDir($dir)
    {
        // Does the directory exist? (if not, error!)
        if (! file_exists($dir) || ! is_dir($dir)) {
            return $this->reportError(sprintf('Error: The specified ZPK data directory "%s" does not exist', $dir));
        }

        // Does the directory contain a deployment.xml file? (if not, error!)
        if (! file_exists($dir . '/deployment.xml')) {
            return $this->reportError(sprintf('Error: The specified ZPK data directory "%s" does not contain a deployment.xml file', $dir));
        }

        return true;
    }

    /**
     * Create a temporary directory for packaging
     *
     * Returns the directory name on success.
     *
     * @return string|false
     */
    protected function createTmpDir()
    {
        $count = 0;
        do {
            $tmpDir = sys_get_temp_dir() . '/' . uniqid("ZFDeploy_");
            $count++;
        } while ($count < 3 && file_exists($tmpDir));

        if ($count >= 3) {
            return $this->reportError('Error: Cannot create a temporary directory in %s', sys_get_temp_dir());
        }

        mkdir($tmpDir);
        return $tmpDir;
    }

    /**
     * Prepare ZPK files
     *
     * Sets up the required directory structure for a ZPK, including adding
     * any desired scripts, the deployment.xml, and the logo.
     *
     * Returns the path to the data directory on completion.
     *
     * If the $format is not zpk, returns $tmpDir.
     *
     * @param string $tmpDir
     * @param string $appname
     * @param string $version
     * @param string $format
     * @param string $deploymentXml
     * @param string $zpkDataDir
     * @param array $zpkDataDir
     * @return string|false
     */
    protected function prepareZpk($tmpDir, $appname, $version, $format, $deploymentXml, $zpkDataDir, array $appConfig)
    {
        if ('zpk' !== $format) {
            return $tmpDir;
        }

        $logo = '';

        // ZPK data path provided; sync it in
        if (! $this->validateZpkDataDir($zpkDataDir)) {
            return false;
        }

        if ($zpkDataDir) {
            $deploymentXml = $zpkDataDir . '/deployment.xml';
            self::recursiveCopy($zpkDataDir, $tmpDir);
        }

        // Create the data directory, if it doesn't exist
        if (! is_dir($tmpDir . '/data')) {
            mkdir($tmpDir . '/data');
        }

        // ZPK data path NOT provided; sync in defaults
        if (! $zpkDataDir) {
            mkdir($tmpDir . '/scripts');
            foreach (glob(__DIR__ . '/../config/zpk/scripts/*.php') as $script) {
                copy($script, $tmpDir . '/scripts/' . basename($script));
            }
        }

        // No deployment.xml provided; use defaults
        if (! $deploymentXml) {
            $logo          = $this->copyLogo($tmpDir, $appConfig);
            $deploymentXml = __DIR__ . '/../config/zpk/deployment.xml';
        }

        // Prepare deployment.xml
        if (false === $this->prepareDeploymentXml($deploymentXml, $tmpDir, $appname, $logo, $version, $format)) {
            return false;
        }

        return $tmpDir .= '/data';
    }

    /**
     * Copy the logo into the ZPK directory
     *
     * Determines whether to use a ZF2 or Apigility logo.
     *
     * @param string $tmpDir
     * @param array $appConfig Application configuration
     * @return string The logo file name
     */
    protected function copyLogo($tmpDir, array $appConfig)
    {
        $logoFile = __DIR__ . '/../config/zpk/logo/zf2-logo.png';
        $logo = 'zf2-logo.png';

        if (isset($appConfig['modules']) && in_array('ZF\Apigility', $appConfig['modules'])) {
            $logoFile = __DIR__ . '/../config/zpk/logo/apigility-logo.png';
            $logo = 'apigility-logo.png';
        }

        copy($logoFile, $tmpDir . '/' . $logo);
        return $logo;
    }

    /**
     * Prepares the default deployment XML
     *
     * Injects the application name, logo, and version, and then validates it
     * before returning.
     *
     * @param string $tmpDir
     * @param string $appname
     * @param string $logo
     * @param string $version
     * @param string $format
     * @return bool
     */
    protected function prepareDeploymentXml($deploymentXml, $tmpDir, $appname, $logo, $version, $format)
    {
        $deployString = file_get_contents($deploymentXml);

        $deployString = str_replace('{NAME}',     $appname,  $deployString);
        $deployString = str_replace('{VERSION}',  $version,  $deployString);
        $deployString = str_replace('{LOGO}',     $logo,     $deployString);

        $packageLocation = $tmpDir . '/deployment.xml';
        file_put_contents($packageLocation, $deployString);

        return $this->validateXml($packageLocation, __DIR__ . '/../config/zpk/schema.xsd');
    }

    /**
     * Clone the application into the build directory
     *
     * @param array $applicationPath
     * @param array $tmpDir
     * @param bool $gitignore
     * @param bool $useVendor
     * @param array $modules
     * @param false|string $configsPath
     */
    protected function cloneApplication($applicationPath, $tmpDir, $gitignore, $useVendor, $modules, $configsPath)
    {
        $exclude = array();
        if (! $useVendor) {
            $exclude[$applicationPath . '/composer.lock'] = true;
            $exclude[$applicationPath . '/vendor'] = true;
        }

        if (is_array($modules) && count($modules) > 0) {
            $exclude[$applicationPath . '/module'] =  true;
        }

        self::recursiveCopy($applicationPath, $tmpDir, $exclude, $gitignore);

        if ($configsPath && is_dir($configsPath)) {
            $tmpConfigPath = $tmpDir . '/config/autoload/';
            foreach (glob($configsPath . '/*.php') as $config) {
                copy($config, $tmpConfigPath . basename($config));
            }
        }
    }

    /**
     * Copy modules into the build directory
     *
     * Only if specific modules were specified via the CLI arguments.
     *
     * @param array $modules
     * @param string $applicationPath
     * @param string $tmpDir
     */
    protected function copyModules(array $modules = null, $applicationPath, $tmpDir)
    {
        if (empty($modules)) {
            return;
        }

        // copy modules
        foreach ($modules as $module) {
            $normalized = str_replace('\\','/', $module);
            self::recursiveCopy($applicationPath . '/module/' . $normalized, $tmpDir . '/module/' . $normalized);
        }
    }

    /**
     * Remove the [tT]est/s directories in vendor for optimization
     *
     * @param string $vendorPath
     */
    protected function removeTestDir($vendorPath)
    {
        $testPath = $vendorPath . '/*/*/[tT]est';
        $testDirs = array_merge(glob($testPath, GLOB_ONLYDIR), glob($testPath . 's', GLOB_ONLYDIR));
        foreach ($testDirs as $dir) {
            self::recursiveDelete($dir);
        }
    }

    /**
     * Perform a recursive copy of a directory
     *
     * @param string $source
     * @param string $dest
     * @param array $exclude
     * @param bool $gitignore
     */
    protected static function recursiveCopy($source, $dest, $exclude = array(), $gitignore = true)
    {
        $dir = opendir($source);
        if (false === $dir) {
            // Unable to open the source directory; nothing to do
            return;
        }

        if ($gitignore && file_exists($source . '/.gitignore')) {
            foreach (file($source . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $git) {
                if (is_file($source . '/' . $git)) {
                    $exclude[$source . '/' . $git] = true;
                    continue;
                }

                foreach(glob($source . '/' . $git) as $file) {
                    $exclude[$file] = true;
                }
            }
        }

        if (! is_dir($dest)) {
            mkdir($dest, 0775, true);
        } while (false !== ( $file = readdir($dir)) ) {
            if ($file === '.' || $file === '..' || $file === '.git') {
                continue;
            }

            if (isset($exclude[$source . '/' . $file]) && $exclude[$source . '/' . $file]) {
                continue;
            }

            if (is_dir($source . '/' . $file)) {
                self::recursiveCopy($source . '/' . $file, $dest . '/' . $file, $exclude);
                continue;
            }

            copy($source . '/' . $file, $dest . '/' . $file);
        }

        closedir($dir);
    }

    /**
     * Recursively delete a directory
     *
     * @param string $dir
     * @return bool
     */
    protected static function recursiveDelete($dir)
    {
        if (false === ($dh = @opendir($dir)))  {
            return false;
        } while (false !== ($obj = readdir($dh))) {
            if ($obj == '.' || $obj == '..') {
                continue;
            }

            if (!@unlink($dir . '/' . $obj)) {
                self::recursiveDelete($dir . '/' . $obj);
            }
        }

        closedir($dh);
        @rmdir($dir);
        return true;
    }

    /**
     * Determine if composer should be executed, and, if so, execute it.
     *
     * @param bool $useVendor
     * @param bool $useComposer
     * @param string $tmpDir
     * @return false|null
     */
    protected function executeComposer($useVendor, $useComposer, $tmpDir)
    {
        if ($useVendor || ! $useComposer) {
            return;
        }

        $composer = $this->getComposerExecutable($tmpDir);
        $command  = sprintf('%s install --no-dev --prefer-dist --optimize-autoloader 2>&1', $composer);

        $this->console->write('Executing ', Color::BLUE);
        $this->console->writeLine($command);

        $curDir = getcwd();
        chdir($tmpDir);
        $result = exec($command);
        chdir($curDir);

        if ($this->downloadedComposer) {
            @unlink($this->downloadedComposer);
            unset($this->downloadedComposer);
        }

        if (empty($result)) {
            return $this->reportError('Composer error during install command');
        }
    }

    /**
     * Determine the Composer executable
     *
     * If 'composer' command is available on the path, use it.
     * If a 'composer.phar' exists in $tmpDir, perform a self-update, and use it.
     * Otherwise, download 'composer.phar' from getcomposer.org, and use it.
     *
     * @param mixed $tmpDir
     * @return string
     */
    protected function getComposerExecutable($tmpDir)
    {
        $result = exec('composer 2>&1');
        if (! empty($result)) {
            return 'composer';
        }

        if (file_exists($tmpDir . '/composer.phar')) {
            // Update it first
            exec(sprintf('%s self-update 2>&1', $tmpDir . '/composer.phar'));

            // Return it
            return 'composer.phar';
        }

        // Try to download it
        file_put_contents($tmpDir . '/composer.phar', 'https://getcomposer.org/composer.phar');
        $this->downloadedComposer = $tmpDir . '/composer.phar';
        return 'composer.phar';
    }

    /**
     * Create the package file
     *
     * @param string $package
     * @param string $dir
     * @param string $format
     * @return bool
     */
    protected function createPackage($package, $dir, $format)
    {
        $this->console->writeLine('Creating package...', Color::BLUE);

        switch ($format) {
            case 'zpk':
                $dir = dirname($dir);
            case 'zip':
                $packager = new ZipArchive;
                $packager->open($package, ZipArchive::CREATE);
                break;
            case 'tar':
                $pharFile = $package;
                $packager = new PharData($pharFile);
                break;
            case 'tar.gz':
                $pharFile = dirname($package) . '/' . basename($package, '.tar.gz') . '.tar';
                $packager = new PharData($pharFile);
                break;
            case 'tgz':
                $pharFile = dirname($package) . '/' . basename($package, '.tgz') . '.tar';
                $packager = new PharData($pharFile);
                break;
            default:
                return $this->reportError(sprintf('Unknown package format "%s"', $format));
        }

        // Create recursive directory iterator
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $this->console->writeLine('Writing files...', Color::BLUE);
        // Remove the relative path
        $dirPos = strlen($dir) + 1;
        switch ($format) {
            case 'zip':
            case 'zpk':
                foreach ($files as $name => $file) {
                    $packager->addFile($file, substr($file, $dirPos));
                }
                break;
            case 'tar':
            case 'tar.gz':
            case 'tgz':
                $packager->buildFromIterator($files, $dir);
                break;
        }

        // Close and finalize the archive
        $this->console->writeLine('Closing package...', Color::BLUE);
        switch ($format) {
            case 'zip':
            case 'zpk':
                $packager->close();
                break;
            case 'tar':
                unset($packager);
                break;
            case 'tar.gz':
                $packager->compress(Phar::GZ, '.tar.gz');
                unset($packager);
                unlink($pharFile);
                break;
            case 'tgz':
                $packager->compress(Phar::GZ, '.tgz');
                unset($packager);
                unlink($pharFile);
                break;
        }

        return true;
    }

    /**
     * Reset internal state for a new execution cycle
     */
    protected function resetStateForExecution(Console $console)
    {
        $this->console = $console;
        $this->downloadedComposer = null;
    }
}
