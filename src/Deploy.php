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
use Zend\Console\Adapter\AdapterInterface as ConsoleAdapter;
use Zend\Console\ColorInterface as Color;
use Zend\Console\Exception\ExceptionInterface as GetoptException;
use Zend\Console\Getopt;
use ZipArchive;

class Deploy
{
    const VERSION = '@package_version@';

    const PROCESS_TITLE = 'ZFDeploy';

    /**
     * Configuration for the application being packaged
     * 
     * @var array
     */
    protected $appConfig = array();

    /**
     * Path to a downloaded composer.phar, if any
     * 
     * @var null|string
     */
    protected $downloadedComposer;

    /**
     * Allows setting an alternate exit status during the options parse stage.
     * 
     * @var null|int
     */
    protected $exitStatus;

    /**
     * Requested package file format
     * 
     * @var string
     */
    protected $format;

    protected $getoptRules = array(
        'help|h'            => 'This usage message',
        'version|v'         => 'Version of this script',
        'package|p-s'       => 'Filename of package to create; can be passed as first argument of script without the option',
        'target|t-s'        => 'Path to application directory; assumes current working directory by default',
        'modules|m-s'       => 'Comma-separated list of specific modules to deploy (all by default)',
        'vendor|e'          => 'Whether or not to include the vendor directory (disabled by default)',
        'composer|c-s'      => 'Whether or not to execute composer; "on" or "off" (on by default)',
        'gitignore|g-s'     => 'Whether or not to parse the .gitignore file to determine what files/folders to exclude; "on" or "off" (on by default)',
        'deploymentxml|d-s' => 'Path to a custom deployment.xml file to use for ZPK packages',
        'appversion|a-s'    => 'Specific application version to use for ZPK packages',
    );

    protected $getoptOptions = array(
        Getopt::CONFIG_PARAMETER_SEPARATOR => ',', // auto-split , separated values
    );

    /**
     * Name of script executing the functionality
     * 
     * @var string
     */
    protected $scriptName;

    protected $validExtensions = array(
        'zip',
        'tar',
        'tar.gz',
        'tgz',
        'zpk',
    );

    public function __construct($scriptName, ConsoleAdapter $console)
    {
        $this->scriptName = $scriptName;
        $this->console = $console;
        $this->getopt = new Getopt($this->getoptRules, array(), $this->getoptOptions);
        $this->setupOptionCallbacks();
    }

    public function execute(array $args)
    {
        $this->setProcessTitle();

        if (false === $this->parseArgs($args)) {
            return (null !== $this->exitStatus) ? $this->exitStatus : 1;
        }

        $opts = $this->getopt;

        if ($opts->version) {
            $this->printVersion();
            return 0;
        }

        if ($opts->help) {
            $this->printUsage();
            return 0;
        }

        $this->console->writeLine(sprintf('Creating package "%s"...', $opts->package), Color::BLUE);
        $this->console->writeLine('');

        if (false === ($tmpDir = $this->createTmpDir())) {
            return 1;
        }

        if (false === ($tmpDir = $this->prepareZpk(
            $tmpDir,
            basename($opts->package, '.' . $this->format),
            $opts->appversion,
            $this->format,
            $opts->deploymentxml))
        ) {
            return 1;
        }

        $this->cloneApplication($opts->target, $tmpDir, $opts->gitignore, $opts->vendor, $opts->modules);
        $this->copyModules($opts->modules, $opts->target, $tmpDir);

        if (false === $this->executeComposer($opts->vendor, $opts->composer)) {
            return 1;
        }

        if (false === $this->createPackage($opts->package, $tmpDir, $format)) {
            return 1;
        }

        self::recursiveDelete($format === 'zpk' ? dirname($tmpDir) : $tmpDir);

        $this->console->writeLine(sprintf(
            '[DONE] Package %s successfully created (%d bytes)',
            $opts->package,
            filesize($opts->package)
        ), Color::GREEN);

        return 0;
    }

    protected function setupOptionCallbacks()
    {
        $self = $this;
        $opts = $this->getopt;

        $opts->setOptionCallback('package', function ($value, $getopt) use ($self) {
            return $self->validatePackageFile($value, $getopt);
        });

        $opts->setOptionCallback('target', function ($value, $getopt) use ($self) {
            return $self->validateApplicationPath($value, $getopt);
        });

        $opts->setOptionCallback('modules', function ($value, $getopt) use ($self) {
            return $self->validateModules($value, $getopt);
        });

        $opts->setOptionCallback('deploymentxml', function ($value, $getopt) use ($self) {
            return $self->validateDeploymentXml($value, $getopt);
        });

        $opts->setOptionCallback('appversion', function ($value, $getopt) use ($self) {
            return $self->validateAppVersion($value, $getopt);
        });
    }

    protected function reportError($message, $usage = false, $color = Color::RED)
    {
        $this->console->writeLine($message, $color);

        if ($usage) {
            $this->printUsage();
        }

        return false;
    }

    protected function setProcessTitle()
    {
        if (version_compare(PHP_VERSION, '5.5', 'lt')) {
            return;
        }

        cli_set_process_title(static::PROCESS_TITLE);
    }

    protected function parseArgs(array $args)
    {
        if (0 === count($args)) {
            $this->exitStatus = 0;
            $this->printUsage();
            return false;
        }

        // 1. Remove first argument, if matches $scriptName.
        $package = array_shift($args);
        if (false !== strrpos($package, $this->scriptName)) {
            if (0 === count($args)) {
                $this->exitStatus = 0;
                $this->printUsage();
                return false;
            }
            $package = array_shift($args);
        }

        // 2. Check next argument to see if it's an option; if so, reset args array
        if (0 === strpos($package, '-')) {
            array_unshift($args, $package);
            unset($package);
        }

        // 3. Add package argument
        if (isset($package)) {
            $args[] = '--package';
            $args[] = $package;
        }

        // 4. Parse getopt
        $opts = $this->getopt;
        $opts->addArguments($args);

        try {
            $opts->parse();
        } catch (GetoptException $e) {
        // 4. If errors, set an error message, and return false.
            return $this->reportError('One or more options were incorrect.', $usage = true);
        }

        // 5. Set default values for composer/gitignore
        $opts->composer  = ($opts->composer === 'off')  ? false : true;
        $opts->gitignore = ($opts->gitignore === 'off') ? false : true;

        // 6. No errors: return true.
        return true;
    }

    protected function printVersion()
    {
        $this->console->writeLine(sprintf('ZFDeploy %s - Deploy Zend Framework 2 applications', static::VERSION), Color::GREEN);
        $this->console->writeLine('');
    }

    protected function printUsage()
    {
        $this->printVersion();
        $this->console->writeLine(sprintf('%s <package file> [options]', $this->scriptName), Color::GREEN);
        $this->console->write($this->getopt->getUsageMessage());
        $this->console->writeLine(sprintf('Copyright %s by Zend Technologies Ltd. - http://framework.zend.com/', date('Y')));
    }

    protected function validateXml($file, $schema)
    {
        if (!file_exists($file)) {
            return $this->reportError(sprintf('The XML file "%s" does not exist.', $file));
        }
        if (!file_exists($schema)) {
            return $this->reportError(sprintf('Error: The XML schema file "%s" does not exist.', $schema));
        }

        // Validate the deployment XML file
        $dom = new DOMDocument();
        $dom->loadXML(file_get_contents($file));
        if (!$dom->schemaValidate($schema)) {
            return $this->reportError(sprintf('The XML file "%s" does not validate against the schema "%s".', $file, $schema));
        }

        return true;
    }

    public function validatePackageFile($value, Getopt $getopt)
    {
        // Do we have a package filename?
        if (!$value) {
            return $this->reportError('Error: missing package filename', true);
        }

        // Does the file already exist? (if so, error!)
        if (file_exists($value)) {
            return $this->reportError(sprintf('Error: package file "%s" already exists', $value));
        }

        // Do we have a valid extension? (if not, error! if so, set $format)
        $format = false;
        $validFormat = array('zip', 'tar', 'tgz', 'tar.gz', 'zpk');
        foreach ($validFormat as $extension) {
            $pattern = '/\.' . preg_quote($extension) . '$/';
            if (preg_match($pattern, $value)) {
                $format = $extension;
                break;
            }
        }
        if (false === $format) {
            $this->reportError(sprintf('Error: package filename "%s" is of an unknown format', $value));
            $this->console->writeLine(sprintf('Valid file formats are: %s', implode(', ', $validFormat)));
            return false;
        }

        // Do we have the PHP extension necessary for the file format? (if not, error!)
        switch ($format) {
            case 'zip':
            case 'zpk':
                if (!extension_loaded('zip')) {
                    return $this->reportError('Error: the ZIP extension of PHP is not loaded.');
                }
                break;

            case 'tar':
            case 'tar.gz':
            case 'tgz':
                if (!class_exists('PharData')) {
                    return $this->reportError('Error: the Phar extension of PHP is not loaded.');
                }
                break;
        }
        $this->format = $format;
        return true;
    }

    public function validateApplicationPath($value, Getopt $getopt)
    {
        // Was it passed? If not, set to getcwd
        if (!$value) {
            $value = getcwd();
        }

        // Is it a directory? (if not, error!)
        if (!is_dir($value)) {
            return $this->reportError(sprintf('Error: the application path "%s" is not valid', $value));
        }

        // Is it a valid ZF2 app? (if not, error!)
        $appConfigPath = $value . '/config/application.config.php';
        if (! file_exists($appConfigPath)) {
            return $this->reportError(sprintf('Error: the folder "%s" does not contain a standard ZF2 application', $value));
        }

        $appConfig = include $appConfigPath;
        if (!$appConfig || !isset($appConfig['modules'])) {
            return $this->reportError(sprintf('Error: the folder "%s" does not contain a standard ZF2 application', $value));
        }

        // Set $this->appConfig when done
        $this->appConfig = $appConfig;
        return true;
    }

    public function validateModules($value, Getopt $getopt)
    {
        // Dependent on target value ($getopt->target)
        if (!$getopt->target) {
            return false;
        }

        // If empty, done
        if (null === $value) {
            return true;
        }

        // If string, cast to array
        if (is_string($value)) {
            $value = array($value);
            $getopt->modules = $value;
        }

        // If not an array, report error
        if (!is_array($value)) {
            return false;
        }

        // Validate each module
        $appPath = $getopt->target;
        foreach ($value as $module) {
            $normalized = str_replace('\\','/', $module);
            if (!is_dir($appPath . '/module/' . $normalized)) {
                return $this->reportError(sprintf('Error: the module "%s" does not exist in %s', $module, $appPath));
            }
        }

        return true;
    }

    public function validateDeploymentXml($value, Getopt $getopt)
    {
        // Does the file exist? (if not, error!)
        if (!file_exists($opts->deploymentxml)) {
            return $this->reportError(sprintf('Error: The deployment XML file "%s" does not exist', $value));
        }

        // Is the file valid? (if not, error!)
        if (!$this->validateXml($value, __DIR__ . '/../config/zpk/schema.xsd')) {
            return $this->reportError(sprintf('Error: The deployment XML file "%s" is not valid', $value));
        }

        return true;
    }

    public function validateAppVersion($value, Getopt $getopt)
    {
        // If not present, set default falue
        if (!$value) {
            $getopt->appversion = date('Y-m-d_H:i');
        }

        return true;
    }

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
    }

    protected function cloneApplication($applicationPath, $tmpDir, $gitignore, $useVendor, $modules)
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
    }

    protected function copyModules(array $modules = null, $applicationPath, $tmpDir)
    {
        if (! $modules) {
            self::recursiveCopy($applicationPath . '/module', $tmpDir . '/module');
            return;
        }

        // copy modules
        foreach ($modules as $module) {
            $normalized = str_replace('\\','/', $module);
            self::recursiveCopy($appPath . '/module/' . $normalized, $tmpDir . '/module/' . $normalized);
        }
    }

    protected static function recursiveCopy($source, $dest, $exclude = array(), $gitignore = true)
    {
        $dir = opendir($source);
        if ($gitignore && file_exists($source . '/.gitignore')) {
            foreach (file($ource . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $git) {
                if (is_file($source . '/' . $git)) {
                    $exclude[$source . '/' . $git] = true;
                    continue;
                }

                foreach(glob($source . '/' . $git) as $file) {
                    $exclude[$file] = true;
                }
            }
        }

        @mkdir($dest, 0775, true);

        while (false !== ( $file = readdir($dir)) ) {
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

    protected static function recursiveDelete($dir)
    {
        if (false === ($dh = @opendir($dir)))  {
            return false;
        }

        while (false !== ($obj = readdir($dh))) {
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

        $curDir = getcwd();
        chdir($tmpDir);
        $result = exec(printf('%s install --no-dev --prefer-dist --optimize-autoloader 2>&1', $composer));
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

    protected function prepareZpk($tmpDir, $appname, $version, $format, $deploymentXml)
    {
        if ('zpk' !== $format) {
            return $tmpDir;
        }

        mkdir($tmpDir . '/data');
        mkdir($tmpDir . '/scripts');
        foreach (glob(__DIR__ . '/../config/zpk/scripts/*.php') as $script) {
            copy($script, $tmpDir . '/scripts');
        }

        if (! $deploymentXml) {
            $logo = $this->copyLogo($tmpDir);
            if (false === ($deploymentXml = $this->prepareDeploymentXml($tmpDir, $appname, $logo, $version, $format))) {
                return false;
            }
            return $tmpDir .= '/data';
        }

        copy($deploymentXml, $tmpDir . '/deployment.xml');
        return $tmpDir .= '/data';
    }

    protected function copyLogo($tmpDir)
    {
        $logoFile = __DIR__ . '/../config/zpk/logo/zf2-logo.png';
        $logo = 'zf2-logo.png';

        if (isset($this->appConfig['modules']) && in_array('ZF\Apigility', $this->appConfig['modules'])) {
            $logoFile = __DIR__ . '/../config/zpk/logo/apigility-logo.png';
            $logo = 'apigility-logo.png';
        }

        copy($logoFile, $tmpDir . '/' . $logo);
        return $logo;
    }

    protected function prepareDeploymentXml($tmpDir, $appname, $logo, $version, $format)
    {
        $defaultDeployXml = __DIR__ . '/../config/zpk/deployment.xml';
        $deployString = file_get_contents($defaultDeployXml);

        $deployString = str_replace('{NAME}',     $appname,  $deployString);
        $deployString = str_replace('{VERSION}',  $version,  $deployString);
        $deployString = str_replace('{LOGO}',     $logo,     $deployString);

        file_put_contents($tmpDir . '/deployment.xml', $deployString);

        return $this->validateXml($defaultDeployXml, __DIR__ . '/../config/zpk/schema.xsd');
    }

    protected function createPackage($package, $dir, $format)
    {
        switch ($format) {
            case 'zpk':
                $dir = dirname($dir);
            case 'zip':
                $packager = new ZipArchive;
                $packager->open($package, ZipArchive::CREATE);
                break;
            case 'tar':
            case 'tar.gz':
            case 'tgz':
                $packager = new PharData($package);
                break;
            default:
                return $this->reportError(sprintf('Unknown package format "%s"', $format));
        }

        // Create recursive directory iterator
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        // Remove the relative path
        $dirPos = strlen($dir) + 1;
        foreach ($files as $name => $file) {
            switch ($format) {
                case 'zip':
                case 'zpk':
                case 'tar':
                case 'tar.gz':
                case 'tgz':
                    $packager->addFile($file, substr($file, $dirPos));
                    break;
            }
        }

        // Close and finalize the archive
        switch ($format) {
            case 'zip':
            case 'zpk':
                $packager->close();
                break;
            /**
             * @todo check this against lines 114 - 117 and lines 458 - 467 of original
             */
            case 'tar.gz':
                $packager->compress(Phar::GZ, '.tar.gz');
                break;
            case 'tgz':
                $packager->compress(Phar::GZ, '.tgz');
                break;
        }
        return true;
    }
}
