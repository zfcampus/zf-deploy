ZFDeploy - deploy ZF2 applications
==================================

**ZFDeploy** is a command line tool to deploy [Zend Framework 2](http://framework.zend.com) applications.

This tool produces a file package ready to be deployed. The tool supports the following format:
ZIP, TAR, TGZ (.TAR.GZ), .ZPK (the deployment file format of [Zend Server 6](http://files.zend.com/help/Zend-Server/zend-server.htm#understanding_the_package_structure.htm)).

Installation
------------

ZFDeploy may be installed in two ways: as a standalone, updatable `phar` file,
or via Composer.

### Standalone PHAR installation

The standalone `phar` file is available at:

- https://packages.zendframework.com/zf-deploy/zfdeploy.phar

You can retrieve it using any of the following commands.

Via `curl`:

```console
$ curl -o zfdeploy.phar https://packages.zendframework.com/zf-deploy/zfdeploy.phar
```

Via `wget`:

```console
$ wget https://packages.zendframework.com/zf-deploy/zfdeploy.phar
```

Or using your installed PHP binary:

```console
php -r "file_put_contents('zfdeploy.phar', file_get_contents('https://packages.zendframework.com/zf-deploy/zfdeploy.phar'));"
```

Once you have the file, you can update it periodically to the latest version using the
`--selfupdate` switch:

```console
$ zfdeploy.phar --selfupdate
```

### Composer installation

Run the following `composer` command:

```console
$ composer require "zfcampus/zf-deploy:~1.0-dev"
```

Alternately, manually add the following to your `composer.json`, in the `require` section:

```javascript
"require": {
    "zfcampus/zf-deploy": "~1.0-dev"
}
```

And then run `composer update` to ensure the module is installed.

If installed via composer, the script lives in `vendor/bin/zfdeploy.php` of your application.

Usage
-----

> ### Note
>
> If you clone this project standalone, the script is located in `bin/zfdeploy.php`. If you install
> this repository as a Composer dependency of your project, the script is located in
> `vendor/bin/zfdeploy.php`. If you install using the `phar` file, you will either need to put it on
> your path or provide the full path to the `phar` file; the script name then is `zfdeploy.phar`.
>
> For our examples, we will reference the script as `zfdeploy`.

The command line tool can be executed using the following command:

```console
$ zfdeploy <package filename>
```

where `<package filename>` is the filename of the output package to produce. When run with no other
arguments, it assumes the current directory should be packaged; if you want to specify a different
directory for packaging, use the `-t` switch:

```console
$ zfdeploy <package filename> -t path/to/application
```

You can specify the file format directly in the `<filename>` using the proper extension (e.g.
`application.zip` will create a ZIP file).

The full syntax of `zfdeploy.php` includes:

```bash
Usage: bin/zfdeploy.php [ options ]
--help|-h                       This usage message
--version|-v                    Version of this script
--package|-p [ <string> ]       Filename of package to create; can be passed as first argument of script without the option
--target|-t [ <string> ]        Path to application directory; assumes current working directory by default
--modules|-m [ <string> ]       Comma-separated list of specific modules to deploy (all by default)
--vendor|-e                     Whether or not to include the vendor directory (disabled by default)
--composer|-c [ <string> ]      Whether or not to execute composer; "on" or "off" (on by default)
--gitignore|-g [ <string> ]     Whether or not to parse the .gitignore file to determine what files/folders to exclude; "on" or "off" (on by default)
--deploymentxml|-d [ <string> ] Path to a custom deployment.xml file to use for ZPK packages
--zpkdata|-z [ <string> ]       Path to a directory containing zpk package assets (deployment.xml, logo, scripts, etc.)
--appversion|-a [ <string> ]    Specific application version to use for ZPK packages
--selfupdate                    (phar version only) Update to the latest version of this tool
```

This deployment tool takes care of the local configuration files, related to the specific
environment, using the `.gitignore` file. If your applications use the `.gitignore` file to exclude
local configuration files, for instance the `local.php` file in the `/config/autoload` folder,
**ZFdeploy** will not include these files in the deployment package. You can disable the usage of
the `.gitignore` file using the `--gitignore off` option.

> ### NOTE: if you disable the .gitignore usage
>
> If you disable the `.gitignore` using the `--gitignore off` option, all the files of the ZF2
> application will be included in the package. **That means local configuration files, including
> sensitive information like database credentials, are deployed in production!!!** Please consider
> this behaviour before switching off the gitignore option.

Another important part of the deployment of a ZF2 application is the usage of
[composer](https://getcomposer.org).

**ZFDeploy** executes the following composer command during the creation of the deployment package:

```bash
$ php composer.phar install --no-dev --prefer-dist --optimize-autoloader
```

The `--no-dev` flag ensures that development packages are not installed in the production
environment.  The `--prefer-dist` option tell composer to install from dist if possible. This can
speed up installs substantially on build servers and other use cases where you typically do not run
updates of the vendors The `--optimize-autoloader` flag makes Composer's autoloader more performant
by building a "class map".

For more information about Composer, you can read the [Documentation](https://getcomposer.org/doc/)
page of the project.
