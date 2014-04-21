<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Deploy;

use DOMDocument;
use FilesystemIterator;
use Herrera\Phar\Update\Manager as UpdateManager;
use Herrera\Phar\Update\Manifest as UpdateManifest;
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
    const MANIFEST_FILE = 'https://packages.zendframework.com/zf-deploy/manifest.json';
    const PROCESS_TITLE = 'ZFDeploy';
    const VERSION = '0.2.0-dev';

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

    /**
     * CLI options
     * 
     * @var array
     */
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
        'zpkdata|z-s'       => 'Path to a directory containing zpk package assets (deployment.xml, logo, scripts, etc.)',
        'appversion|a-s'    => 'Specific application version to use for ZPK packages',
        'selfupdate'        => '(phar version only) Update to the latest version of this tool',
    );

    /**
     * Getopt behavior options
     * 
     * @var array
     */
    protected $getoptOptions = array(
        Getopt::CONFIG_PARAMETER_SEPARATOR => ',', // auto-split , separated values
    );

    /**
     * Whether or not one or more options were marked as invalid.
     * 
     * @var bool
     */
    protected $invalid = false;

    /**
     * Name of script executing the functionality
     * 
     * @var string
     */
    protected $scriptName;

    /**
     * Valid package file extensions
     * 
     * @var array
     */
    protected $validExtensions = array(
        'zip',
        'tar',
        'tar.gz',
        'tgz',
        'zpk',
    );

    /**
     * @param mixed $scriptName 
     * @param ConsoleAdapter $console 
     */
    public function __construct($scriptName, ConsoleAdapter $console)
    {
        $this->scriptName = $scriptName;
        $this->console = $console;
        $this->getopt = new Getopt($this->getoptRules, array(), $this->getoptOptions);
        $this->setupOptionCallbacks();
    }

    /**
     * Perform all operations
     *
     * Facade method that accepts incoming CLI arguments, parses them, and
     * determines what workflows to execute.
     * 
     * @param array $args 
     * @return int exit status
     */
    public function execute(array $args)
    {
        $this->resetStateForExecution();

        $this->setProcessTitle();

        if (false === $this->parseArgs($args) || $this->invalid) {
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

        if ($opts->selfupdate) {
            $this->updatePhar();
            return 0;
        }

        $this->console->writeLine(sprintf('Creating package "%s"...', $opts->package), Color::BLUE);

        if (false === ($tmpDir = $this->createTmpDir())) {
            return 1;
        }

        if (false === ($tmpDir = $this->prepareZpk(
            $tmpDir,
            basename($opts->package, '.' . $this->format),
            $opts->appversion,
            $this->format,
            $opts->deploymentxml,
            $opts->zpkdata))
        ) {
            return 1;
        }

        $this->cloneApplication($opts->target, $tmpDir, $opts->gitignore, $opts->vendor, $opts->modules);
        $this->copyModules($opts->modules, $opts->target, $tmpDir);

        if (false === $this->executeComposer($opts->vendor, $opts->composer, $tmpDir)) {
            return 1;
        }

        if (false === $this->createPackage($opts->package, $tmpDir, $this->format)) {
            return 1;
        }

        self::recursiveDelete($this->format === 'zpk' ? dirname($tmpDir) : $tmpDir);

        $this->console->writeLine(sprintf(
            '[DONE] Package %s successfully created (%d bytes)',
            $opts->package,
            filesize($opts->package)
        ), Color::GREEN);

        return 0;
    }

    /**
     * Sets up callbacks for named getopt options, allowing further validation.
     */
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

        $opts->setOptionCallback('zpkdata', function ($value, $getopt) use ($self) {
            return $self->validateZpkData($value, $getopt);
        });
    }

    /**
     * Report an error
     *
     * Marks the current state as invalid, and emits an error message to the
     * console.
     *
     * If the $usage flag is true, also prints usage.
     *
     * Allows passing in a specific color to use when emitting the error
     * message; defaults to red.
     * 
     * @param string $message 
     * @param bool $usage 
     * @param string $color 
     * @return false
     */
    protected function reportError($message, $usage = false, $color = Color::RED)
    {
        $this->invalid = true;

        $this->console->writeLine($message, $color);

        if ($usage) {
            $this->printUsage();
        }

        return false;
    }

    /**
     * Set the console process title
     *
     * Only for 5.5 and above.
     */
    protected function setProcessTitle()
    {
        if (version_compare(PHP_VERSION, '5.5', 'lt')) {
            return;
        }

        cli_set_process_title(static::PROCESS_TITLE);
    }

    /**
     * Parse incoming arguments
     *
     * No arguments: print usage.
     *
     * First argument is the script and the only argument: print usage.
     * Otherwise, shift it off and continue.
     *
     * First argument is the package name: pass remaining arguments to Getopt,
     * and assign the package name to getopts on completion.
     *
     * First argument is an option: pass all arguments to Getopt.
     *
     * If getopt raises an exception, report usage.
     *
     * On successful completion, set argument defaults for arguments that
     * were not passed, and return.
     * 
     * @param array $args 
     * @return bool
     */
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

        // 5. Set default values
        if (! $opts->target
            && ! $opts->help
            && ! $opts->version
        ) {
            if (!$this->validateApplicationPath(getcwd(), $opts)) {
                return false;
            }
            $opts->target = getcwd();
        }
        $opts->composer   = ($opts->composer === 'off')  ? false : true;
        $opts->gitignore  = ($opts->gitignore === 'off') ? false : true;
        $opts->appversion = $opts->appversion ?: date('Y-m-d_H:i');
        $opts->modules    = is_string($opts->modules) ? array($opts->modules) : $opts->modules;

        // 6. No errors: return true.
        return true;
    }

    /**
     * Emit the script version
     */
    protected function printVersion()
    {
        $this->console->writeLine(sprintf('ZFDeploy %s - Deploy Zend Framework 2 applications', static::VERSION), Color::GREEN);
        $this->console->writeLine('');
    }

    /**
     * Emit the usage message
     */
    protected function printUsage()
    {
        $this->printVersion();
        $this->console->writeLine(sprintf('%s <package file> [options]', $this->scriptName), Color::GREEN);
        $this->console->write($this->getopt->getUsageMessage());
        $this->console->writeLine(sprintf('Copyright %s by Zend Technologies Ltd. - http://framework.zend.com/', date('Y')));
    }

    /**
     * Perform a self-update of a PHAR file
     */
    protected function updatePhar()
    {
        $manager = new UpdateManager(UpdateManifest::loadFile(self::MANIFEST_FILE));
        $manager->update(self::VERSION, true, true);
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

    /**
     * Validate the package file argument
     *
     * Determines the format, and, if the package file is valid, sets the
     * format for this invocation.
     * 
     * @param string $value 
     * @param Getopt $getopt 
     * @return bool
     */
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
        foreach ($this->validExtensions as $extension) {
            $pattern = '/\.' . preg_quote($extension) . '$/';
            if (preg_match($pattern, $value)) {
                $format = $extension;
                break;
            }
        }
        if (false === $format) {
            $this->reportError(sprintf('Error: package filename "%s" is of an unknown format', $value));
            $this->console->writeLine(sprintf('Valid file formats are: %s', implode(', ', $this->validExtensions)));
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

    /**
     * Validate the application path
     *
     * If valid, also sets the $appConfig property.
     * 
     * @param string $value 
     * @param Getopt $getopt 
     * @return bool
     */
    public function validateApplicationPath($value, Getopt $getopt)
    {
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

    /**
     * Validate the modules list
     *
     * @param null|string|array $value 
     * @param Getopt $getopt 
     * @return bool
     */
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

    /**
     * Validate a submitted deployment.xml
     * 
     * @param string $value 
     * @param Getopt $getopt 
     * @return bool
     */
    public function validateDeploymentXml($value, Getopt $getopt)
    {
        // Does the file exist? (if not, error!)
        if (! file_exists($value)) {
            return $this->reportError(sprintf('Error: The deployment XML file "%s" does not exist', $value));
        }

        // Is the file valid? (if not, error!)
        if (! $this->validateXml($value, __DIR__ . '/../config/zpk/schema.xsd')) {
            return $this->reportError(sprintf('Error: The deployment XML file "%s" is not valid', $value));
        }

        return true;
    }

    /**
     * Validate a ZPK data directory
     * 
     * @param string $value 
     * @param Getopt $getopt 
     * @return bool
     */
    public function validateZpkData($value, Getopt $getopt)
    {
        // Does the directory exist? (if not, error!)
        if (! file_exists($value) || ! is_dir($value)) {
            return $this->reportError(sprintf('Error: The specified ZPK data directory "%s" does not exist', $value));
        }

        // Does the directory contain a deployment.xml file? (if not, error!)
        if (! file_exists($value . '/deployment.xml')) {
            return $this->reportError(sprintf('Error: The specified ZPK data directory "%s" does not contain a deployment.xml file', $value));
        }

        // Is the deployment.xml file valid? (if not, error!)
        if (! $this->validateXml($value . '/deployment.xml', __DIR__ . '/../config/zpk/schema.xsd')) {
            return $this->reportError(sprintf('Error: The deployment XML file "%s" is not valid', $value . '/deployment.xml'));
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
     * @return string|false
     */
    protected function prepareZpk($tmpDir, $appname, $version, $format, $deploymentXml, $zpkDataDir)
    {
        if ('zpk' !== $format) {
            return $tmpDir;
        }

        $logo = '';

        // ZPK data path provided; sync it in
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
                copy($script, $tmpDir . '/scripts');
            }
        }

        // No deployment.xml provided; use defaults
        if (! $deploymentXml) {
            $logo          = $this->copyLogo($tmpDir);
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
     * @return string The logo file name
     */
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
     */
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
        if (! $modules) {
            return;
        }

        // copy modules
        foreach ($modules as $module) {
            $normalized = str_replace('\\','/', $module);
            self::recursiveCopy($applicationPath . '/module/' . $normalized, $tmpDir . '/module/' . $normalized);
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
    protected function resetStateForExecution()
    {
        $this->appConfig = array();
        $this->downloadedComposer = null;
        $this->exitStatus = null;
        $this->format = null;
        $this->invalid = false;
    }
}
