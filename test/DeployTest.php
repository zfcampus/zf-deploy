<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */
namespace ZFTest\Deploy;

use PHPUnit_Framework_TestCase as TestCase;
use ZF\Deploy\Deploy;
use Zend\Console\Console;
use ZF\Console\Route;

class DeployTest extends TestCase
{
    protected $routes;
    protected $console;
    protected $deployFile;
    protected $deploy;
    protected $tmpDir;

    /**
     * setUp from PHPUnit
     */
    public function setUp()
    {
        ob_start();
        $this->console = Console::getInstance();
        $this->routes = include __DIR__ . '/../config/routes.php';
        $this->deploy = new Deploy();
        copy(
            __DIR__ . '/TestAsset/App/config/autoload/.gitignore.dist',
            __DIR__ . '/TestAsset/App/config/autoload/.gitignore'
        );
    }

    /**
     * tearDown from PHPUnit
     */
    public function tearDown()
    {
        if (file_exists($this->deployFile)) {
            unlink($this->deployFile);
        }
        if (file_exists($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
        if (file_exists(__DIR__ . '/TestAsset/App/config/autoload/.gitignore')) {
            unlink(__DIR__ . '/TestAsset/App/config/autoload/.gitignore');
        }
        ob_end_clean();
    }

    /**
     * Get the route parameters from the router config array
     *
     * @param  string                    $name
     * @return \ZF\Console\Route|boolean
     */
    protected function getRoute($name)
    {
        foreach ($this->routes as $spec) {
            if ($spec['name'] === $name) {
                $name = $spec['name'];
                $routeString = $spec['route'];

                $constraints = (isset($spec['constraints']) && is_array($spec['constraints']))
                    ? $spec['constraints']
                    : array();
                $defaults = (isset($spec['defaults']) && is_array($spec['defaults'])) ? $spec['defaults'] : array();
                $aliases = (isset($spec['aliases']) && is_array($spec['aliases'])) ? $spec['aliases'] : array();
                $filters = (isset($spec['filters']) && is_array($spec['filters'])) ? $spec['filters'] : null;
                $validators = (isset($spec['validators']) && is_array($spec['validators']))
                    ? $spec['validators']
                    : null;
                $description = (isset($spec['description']) && is_string($spec['description']))
                    ? $spec['description']
                    : '';
                $shortDescription = (isset($spec['short_description']) && is_string($spec['short_description']))
                    ? $spec['short_description']
                    : '';
                $optionsDescription = (isset($spec['options_descriptions']) && is_array($spec['options_descriptions']))
                    ? $spec['options_descriptions']
                    : array();

                $route = new Route($name, $routeString, $constraints, $defaults, $aliases, $filters, $validators);
                $route->setDescription($description);
                $route->setShortDescription($shortDescription);
                $route->setOptionsDescription($optionsDescription);

                return $route;
            }
        }

        return false;
    }

    /**
     * Test the constructor
     */
    public function testConstruct()
    {
        $this->assertTrue($this->deploy instanceof Deploy);
    }

    /**
     * Test the build command, creating a zip file
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App
     */
    public function testBuildZip()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($this->deployFile));
            $this->assertInternalType('int', $zip->locateName('composer.json'));
            // check if .gitignore is working correctly
            $this->assertFalse($zip->locateName('config/autoload/local.php'));
            // check if the test folders in vendor are excluded
            $this->assertFalse($zip->getFromName('vendor/zendframework/zf2/test/README.md'));
            // check if composer has been executed correctly
            $this->assertInternalType('int', $zip->locateName('vendor/autoload.php'));
            $zip->close();
        }
    }

    /**
     * Test the build command, creating a tgz file
     *
     * Command: zfdeploy.php build ./TestAsset/build.tgz --target ./TestAsset/App
     */
    public function testBuildTgz()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.tgz';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (class_exists('PharData')) {
            $tgz = new \PharData($this->deployFile);
            $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
            mkdir($this->tmpDir);
            $this->assertTrue($tgz->extractTo($this->tmpDir, 'composer.json'));
        }
    }

    /**
     * Test the build command, creating a zpk file
     *
     * Command: zfdeploy.php build ./TestAsset/build.zpk --target ./TestAsset/App
     */
    public function testBuildZpk()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zpk';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($this->deployFile));
            $this->assertInternalType('int', $zip->locateName('deployment.xml'));
            $this->assertInternalType('int', $zip->locateName('data/composer.json'));
            $zip->close();
        }
    }

    /**
     * Test the build command selecting only one module to deploy
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App --modules Application
     */
    public function testBuildSelectOneModule()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array(
            'build',
            $this->deployFile,
            '--target',
            __DIR__ . '/TestAsset/App',
            '--modules', 'Application'
        ));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/module/Application/Module.php');
        $this->assertFileNotExists($this->tmpDir . '/module/Test/Module.php');
        $config = include $this->tmpDir . '/config/application.config.php';
        $this->assertEquals($config['modules'], array('ZfcBase', 'ZfcUser', 'Application'));
    }

    /**
     * Test the build command copying the vendor folder
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App --vendor
     */
    public function testBuildWithVendorFolder()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App', '--vendor'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/vendor/README.md');
        $this->assertFileNotExists($this->tmpDir . '/vendor/autoload.php');
    }

    /**
     * Test the build command without the execution of composer
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App --composer off
     */
    public function testBuildWithoutComposer()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App', '--composer', 'off'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $this->assertFileNotExists($this->tmpDir . '/vendor');
    }

    /**
     * Test the build command without the .gitignore parsing
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App --gitignore off
     */
    public function testBuildWithoutGitignore()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset/App', '--gitignore', 'off'));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/config/autoload/local.php');
    }

    /**
     * Test the build command adding config files in the package
     *
     * Command: zfdeploy.php build ./TestAsset/build.zip --target ./TestAsset/App --configs ./TestAsset/config
     */
    public function testBuildWithConfig()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array(
            'build',
            $this->deployFile,
            '--target',
            __DIR__ . '/TestAsset/App',
            '--configs',
            __DIR__ . '/TestAsset/config'
        ));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $this->assertFileExists($this->tmpDir . '/config/autoload/config.php');
    }

    /**
     * Test the build command with a specific deployment.xml file for ZPK format
     *
     * Command: zfdeploy.php build ./TestAsset/build.zpk \
     *     --target ./TestAsset/App \
     *     --deploymentxml ./TestAsset/zpk/deployment.xml
     */
    public function testBuildWithDeploymentXml()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zpk';
        $route->match(array(
            'build',
            $this->deployFile,
            '--target',
            __DIR__ . '/TestAsset/App',
            '--deploymentxml',
            __DIR__ . '/TestAsset/zpk/deployment.xml'
        ));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $origin      = file_get_contents(__DIR__ . '/TestAsset/zpk/deployment.xml');
        $destination = file_get_contents($this->tmpDir . '/deployment.xml');
        $this->assertEquals($origin, $destination);
    }

    /**
     * Test the build command with a specific ZPK data folder
     *
     * Command: zfdeploy.php build ./TestAsset/build.zpk --target ./TestAsset/App --zpkdata ./TestAsset/zpk
     */
    public function testBuildWithZpkData()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zpk';
        $route->match(array(
            'build',
            $this->deployFile,
            '--target',
            __DIR__ . '/TestAsset/App',
            '--zpkdata',
            __DIR__ . '/TestAsset/zpk'
        ));
        $deploy($route, $this->console);
        $this->assertTrue(file_exists($this->deployFile));

        if (!class_exists('ZipArchive')) {
            $this->markTestIncomplete('I cannot test without the Zip PHP extension installed');
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->deployFile));
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('testZfDeploy');
        $zip->extractTo($this->tmpDir);
        $origin      = file_get_contents(__DIR__ . '/TestAsset/zpk/deployment.xml');
        $destination = file_get_contents($this->tmpDir . '/deployment.xml');
        $this->assertEquals($origin, $destination);
        $this->assertFileExists($this->tmpDir . '/scripts/pre-stage.php');
    }

    /**
     * Test the build command with a non-ZF2 application
     */
    public function testNonZF2ApplicationRaisesException()
    {
        $deploy = $this->deploy;
        $route = $this->getRoute('build');
        $this->deployFile = __DIR__ . '/TestAsset/build.zip';
        $route->match(array('build', $this->deployFile, '--target', __DIR__ . '/TestAsset'));
        $this->assertEquals(1, $deploy($route, $this->console));
        $this->assertContains('does not contain a standard ZF2 application', ob_get_contents());
    }

    /**
     * Remove a dir, even if not empty
     *
     * @param string $dir
     */
    protected function removeDir($dir)
    {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }

        return @rmdir($dir);
    }
}
