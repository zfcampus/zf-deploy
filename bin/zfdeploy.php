#!/usr/bin/env php
<?php
/**
 * Deploy Zend Framework 2 application (Apigility support included)
 *
 * @link      http://github.com/zendframework/ZFTool for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

define ('ZFDEPLOY_VER', '0.1');
$validFormat = array('zip', 'tar', 'tgz', 'tar.gz', 'zpk');

ini_set('user_agent', 'ZFDeploy - deploy ZF2 applications, command line tool');

if (count($argv) < 4) {
   printUsage();
   exit(1);
}

list(,$appPath, $out, $fileOut) = $argv;
if (!is_dir($appPath)) {
    printf("\033[31mError: the path %s is not valid\033[0m\n", $appPath);
    exit(1);
}

// Check for a valid ZF2 application in $appPath
$appConfig = @require $appPath . '/config/application.config.php';
if (!$appConfig || !isset($appConfig['modules'])) {
    printf("\033[31mError: the folder %s doesn't contain a standard ZF2 application\033[0m\n", $appPath);
    exit(1);
}

if (strtolower($out) !== '-o') {
    printUsage();
    exit(1);
}

if (file_exists($fileOut)) {
    printf("\033[31mError: the file %s already exists!\033[0m\n", $fileOut);
    exit(1);
}

preg_match('/\.(.*)$/', $fileOut, $matches);
if (!isset($matches[1]) || !in_array($matches[1], $validFormat)) {
    printf("\033[31mError: I cannot recognize the file format of the package %s\033[0m\n", $fileOut);
    printf("Valid file formats are: %s\n", implode(', ', $validFormat));
    exit(1);
}
$format = $matches[1];

if ($format == 'tgz' || $format == 'tar.gz') {
    $fileOut = ($format == 'tgz') ? substr($fileOut, 0, -3) : substr($fileOut, 0, -6);
    $fileOut .= 'tar';
}

// Modules to deploy (optional)
$pos = array_search('-m', $argv);
if (false !== $pos) {
    if (!isset($argv[$pos+1])) {
        printUsage();
        exit(1);
    }
    $modToDeploy = explode(',', $argv[$pos+1]);
}

// Include the vendor folder (optional)
$pos = array_search('-vendor', $argv);
if (false !== $pos) {
    $vendor = true;
}

// Composer execution true/false (optional)
$composer = true;
$pos = array_search('-composer', $argv);
if (false !== $pos) {
    if (!isset($argv[$pos+1]) || !in_array(strtolower($argv[$pos+1]), array('on','off'))) {
        printUsage();
        exit(1);
    }
    $composer = strtolower($argv[$pos+1]) === 'on' ? true : false;
}

// Gitignore parse true/false (optional)
$gitignore = true;
$pos = array_search('-gitignore', $argv);
if (false !== $pos) {
    if (!isset($argv[$pos+1]) || !in_array(strtolower($argv[$pos+1]), array('on','off'))) {
        printUsage();
        exit(1);
    }
    $gitignore = strtolower($argv[$pos+1]) === 'on' ? true : false;
}

// Specify a deployment.xml for zpk format (optional)
$pos = array_search('-d', $argv);
if (false !== $pos) {
    if (!isset($argv[$pos+1])) {
        printUsage();
        exit(1);    
    }
    if (!file_exists($argv[$pos+1])) {
        printf("\033[31mError: The deployment XML file %s doesn't exist\033[0m\n", $argv[$pos+1]);
        exit(1);
    }
    if (!validateXml($argv[$pos+1], __DIR__ . '/../config/zpk/schema.xsd')) {
        printf("\033[31mError: The deployment XML file %s is not valid\033[0m\n", $file);
        exit(1);
    }
    $deployXML = $argv[$pos+1];
}

// Specify a version to include in the zpk (optional)
$pos = array_search('-ver', $argv);
if (false !== $pos) {
    if (!isset($argv[$pos+1])) {
        printUsage();
        exit(1);    
    }
    $version = $argv[$pos+1];
} 
if (!isset($version)) {
    $version = date("Y-m-d_H:i");
}

// Check for requirements
checkRequirements($format);

printf("\033[32mCreating the %s deploy package...\n\033[0m", strtoupper($format));

$modules = glob($appPath . '/module/*', GLOB_ONLYDIR);

// Create a temporary directory
$count = 0;
do {
    $tmpDir = sys_get_temp_dir() . '/' . uniqid("ZFDeploy_");
    $count++;
} while ($count < 3 && file_exists($tmpDir));
if ($count >= 3) {
    printf("\033[31mError: I cannot create a temporary directory in %s\033[0m\n", sys_get_temp_dir());
    exit(1);
}
mkdir($tmpDir);

// Zend Server format package (.zpk)
if ($format === 'zpk') {
    mkdir($tmpDir . '/data');
    mkdir($tmpDir . '/scripts');
    foreach (glob(__DIR__ . '/../config/zpk/scripts/*.php') as $script) {
        copy($script, $tmpDir . '/scripts');
    }
    if (isset($deployXML)) {
        copy($deployXML, $tmpDir . '/deployment.xml');
    } else {
        $defaultDeployXml = __DIR__ . '/../config/zpk/deployment.xml';
        $deployString = file_get_contents($defaultDeployXml);
        $deployString = str_replace('{NAME}', basename($fileOut, ".$format"), $deployString);
        $deployString = str_replace('{VERSION}', $version, $deployString);
        $logoFile = __DIR__ . '/../config/zpk/logo/zf2-logo.png';
        $logo = 'zf2-logo.png';
        if (in_array('ZF\Apigility', $appConfig['modules'])) {
            $logoFile = __DIR__ . '/../config/zpk/logo/apigility-logo.png';
            $logo = 'apigility-logo.png';
        }
        copy($logoFile, $tmpDir . '/' . $logo);
        $deployString = str_replace('{LOGO}', $logo, $deployString); 
        file_put_contents($tmpDir . '/deployment.xml', $deployString);
        if (!validateXml($defaultDeployXml, __DIR__ . '/../config/zpk/schema.xsd')) {
            printf("\033[31mError: The default file %s is not valid. Check the configuration, please.\033[0m\n", $defaultDeployXml);
            exit(1);
        }
    }
    $tmpDir .= '/data';
}

// Copy the modules
if (isset($modToDeploy)) {
    foreach ($modToDeploy as $mod) {
        $modToCopy = str_replace('\\','/', $mod);
        if (!is_dir($appPath . '/module/' . $modToCopy)) {
            printf("\033[31mError: the module %s doesn't exist in %s\033[0m\n", $mod, $appPath);
            exit(1);
        }
        recursiveCopy($appPath . '/module/' . $modToCopy, $tmpDir . '/module/' . $modToCopy);
    }
} else {
    recursiveCopy($appPath . '/module', $tmpDir . '/module');
}

// File/folder to exclude from the copy
$exclude = array(
    $appPath . '/module' => true
);

if (!isset($vendor)) {
    $exclude[$appPath . '/composer.lock'] = true;
    $exclude[$appPath . '/vendor'] = true;
}
recursiveCopy($appPath, $tmpDir, $exclude, $gitignore);

if (!isset($vendor) && $composer) {
    printf("\033[32mExecuting composer install... (be patient please)\033[0m\n");
    
    // Execute the composer install
    chdir($tmpDir);
    $result = exec("composer 2>&1");
    if (empty($result)) {
        $downloaded = false;
        if (!file_exists($tmpDir . '/composer.phar')) {
            // Try to download composer.phar from getcomposer.org
            file_put_contents('composer.phar','https://getcomposer.org/composer.phar');
            $downloaded = true;
        } else {
            // Self update of composer
            exec("php composer.phar self-update 2>&1"); 
        }    
        $result = exec("php composer.phar install --no-dev --prefer-dist --optimize-autoloader 2>&1");
        if ($downloaded) {
            @unlink ($tmpDir . '/composer.phar');
        }
    } else {
        $result = exec("composer install --no-dev --prefer-dist --optimize-autoloader 2>&1");
    } 
            
    if (empty($result)) {
        printf("\033[31mError: composer error during the install command\033[0m\n");
        exit(1);
    }
}

if ($format === 'zpk') {
    $tmpDir = dirname($tmpDir);
}
if (!createPackage($fileOut, $tmpDir, $format)) {
    printf("\033[31mError during the package creation.\033[0m\n");
    exit(1);
}

recursiveDelete($tmpDir);

if ($format === 'tar.gz' || $format === 'tgz') {
    $fileOut = substr($fileOut, 0 , -3) . $format;
} 
printf("\033[32mDone! Package successfully created in %s (%d bytes)\033[0m\n", $fileOut, filesize($fileOut));


/**
 * Print the usage command and options
 */
function printUsage()
{
    printf("\033[33mZFDeploy %s - Deploy Zend Framework 2 applications\033[0m\n", ZFDEPLOY_VER);
    printf("\033[32mUsage: %s <path> -o <filename> \033[0m\n", basename(__FILE__));
    printf("\033[32m       [-m <modules>] [-vendor] [-composer <on|off>]\033[0m\n"); 
    printf("\033[32m       [-gitignore <on|off>] [-d <deploy.xml>] [-ver <version>]\033[0m\n");
    printf("\033[32m<path>\033[0m              Path of the application to deploy\n");
    printf("\033[32m-o <filename>\033[0m       Filename of the package output to deploy\n");
    printf("\033[37mOptional parameters:\033[0m\n");
    printf("\033[32m-m <modules>\033[0m        The list of modules to deploy, separated by comma (if empty deploy all)\n");
    printf("\033[32m-vendor\033[0m             Include the vendor folder (not included by default)\n");
    printf("\033[32m-composer <on|off>\033[0m  Determine if execute composer install (on by default)\n");
    printf("\033[32m-gitignore <on|off>\033[0m Determine if parse the .gitignore to exclude file/folder (on by default)\n");
    printf("\033[32m-d <deploy.xml>\033[0m     Specify the deployment.xml file to use for ZPK format (default in config/zpk/deployment.xml)\n");
    printf("\033[32m-ver <version>\033[0m      Specify the application version to use for ZPK format (default is timestamp)\n");
    printf("\033[37mCopyright 2005-%s by Zend Technologies Ltd. - http://framework.zend.com\033[0m\n", date("Y"));
}

/**
 * Check for requirements
 *
 * @param string $format
 */
function checkRequirements($format) {
    switch ($format) {
        case 'zip':
        case 'zpk':
            if (!extension_loaded('zip')) {
                printf("\033[31mError: the ZIP module of PHP is not loaded.\033[0m\n");
                exit(1);
            }
            break;

        case 'tar':
        case 'tar.gz':    
        case 'tgz':
            if (!class_exists('PharData')) {
                printf("\033[31mError: the Phar extension of PHP is not loaded.\033[0m\n");
                exit(1);
            } 
            break;    
    }
}

/**
 * Validate an XML file against a XSD schema
 *
 * @param string $file
 * @param string $schema
 */
function validateXml($file, $schema) {
    if (!file_exists($file)) {
        printf("\033[31mError: The %s file doesn't exists.\033[0m\n", $file);
        exit(1);
    }
    if (!file_exists($schema)) {
        printf("\033[31mError: The %s schema doesn't exists.\033[0m\n", $schema);
        exit(1);
    }
    // Validate the deployment XML file
    $dom = new \DOMDocument();
    $dom->loadXML(file_get_contents($file));
    return $dom->schemaValidate($schema);
}

/**
 * Recursive file copy with exclude and .gitignore support
 *
 * @param string  $src       Source dir
 * @param string  $dst       Destination dir
 * @param array   $exclude   File/folder to exclude in the copy as associative array (true/false)
 * @param boolean $gitignore Determine if parse the .gitignore file to exclude file/folder in the copy
 */
function recursiveCopy($src, $dst, $exclude = array(), $gitignore = true) {
    $dir = opendir($src);
    if ($gitignore && file_exists($src . '/.gitignore')) {
        foreach (file($src . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $git) {
            if (is_file($src . '/' . $git)) {
                $exclude[$src . '/' . $git] = true;
            } else {
                foreach(glob($src . '/' . $git) as $file) {
                    $exclude[$file] = true;
                }
            }
        }
    }
    @mkdir($dst, 0775, true);
    while(false !== ( $file = readdir($dir)) ) {
        if ($file === '.' || $file === '..' || $file === '.git') {
            continue;
        }
        if (isset($exclude[$src . '/' . $file]) && $exclude[$src . '/' . $file]) {
            continue;
        } 
        if (is_dir($src . '/' . $file)) {
            recursiveCopy($src . '/' . $file, $dst . '/' . $file, $exclude);
        } else {
            copy($src . '/' . $file, $dst . '/' . $file);
        }
    }
    closedir($dir);
}

/**
 * Recursively delete a directory
 *
 * @param string $dir Directory to delete
 */
function recursiveDelete($dir)
{
    if (!$dh = @opendir($dir))  {
        return false;
    }
    while (false !== ($obj = readdir($dh))) {
        if ($obj == '.' || $obj == '..') {
            continue;
        }

        if (!@unlink($dir . '/' . $obj)) {
            recursiveDelete($dir.'/'.$obj, true);
        }
    }

    closedir($dh);
    @rmdir($dir);
    return true;
} 

/**
 * Create a ZIP file
 *
 * @param string $fileOut   Package to create
 * @param string $dir       Directory to deploy
 * @param string $format    Package format (zip, tgz, zpk)
 * @return boolean
 */
function createPackage($fileOut, $dir, $format)
{
    switch ($format) {
        case 'zip':
        case 'zpk':
            $zip = new ZipArchive;
            $zip->open($fileOut, ZipArchive::CREATE);
            break;
        case 'tar':
        case 'tar.gz':    
        case 'tgz':
            $tgz = new PharData($fileOut);
            break;
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
                $zip->addFile($file, substr($file, $dirPos));
                break;
            case 'tar':    
            case 'tar.gz':    
            case 'tgz':
                $tgz->addFile($file, substr($file, $dirPos));
                break;
        }
    }
    switch ($format) {
        case 'zip':
        case 'zpk':
            $zip->close();
            break;
        case 'tar.gz':    
            $tgz->compress(Phar::GZ);
            unlink($fileOut);
            break;
        case 'tgz':    
            $tgz->compress(Phar::GZ);
            unlink($fileOut);
            $file = substr($fileOut, 0, -3);
            rename($file . 'tar.gz', $file . 'tgz');
            break;
    }    
    return true;
}
