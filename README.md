ZFDeploy - deploy ZF2 applications
==================================

[![Build Status](https://travis-ci.org/zfcampus/zf-deploy.png)](https://travis-ci.org/zfcampus/zf-deploy)

Introduction
------------

**ZFDeploy** is a command line tool to deploy [Zend Framework 2](http://framework.zend.com) applications.

This tool produces a file package ready to be deployed. The tool supports the following format:
ZIP, TAR, TGZ (.TAR.GZ), .ZPK (the deployment file format of [Zend Server 6](http://files.zend.com/help/Zend-Server/zend-server.htm#understanding_the_package_structure.htm)).

Requirements
------------
  
Please see the [composer.json](composer.json) file.

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
$ php -r "file_put_contents('zfdeploy.phar', file_get_contents('https://packages.zendframework.com/zf-deploy/zfdeploy.phar'));"
```

Once you have the file, make it executable; in Unix-like systems:

```console
$ chmod 755 zfdeploy.phar
```

You can update the `phar` file periodically to the latest version using the `self-update` command:

```console
$ zfdeploy.phar self-update
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
> Depending on your environment, you may need to execute the `phar` file or `php` script using your
> `php` executable:
>
> ```console
> $ php bin/zfdeploy.php
> $ php vendor/bin/zfdeploy.php
> $ php zfdeploy.phar
> ```
>
> In most Unix-like systems, if you have `/usr/bin/env` available, both the script and `phar` file
> should be self-executable.
>
> For our examples, we will reference the script as `zfdeploy`, regardless of how you installed it
> or how you determine you will need to execute it.

The command line tool can be executed using the following command:

```console
$ zfdeploy build <package>
```

where `<package>` is the filename of the output package to produce. When run with no other
arguments, it assumes the current directory should be packaged; if you want to specify a different
directory for packaging, use the `--target` flag:

```console
$ zfdeploy build <package> --target path/to/application
```

You can specify the file format directly in the `<package>` using the proper extension (e.g.
`application.zip` will create a ZIP file).

`zfdeploy` includes the following commands:

```console
$ zfdeploy
ZFDeploy, version 0.3.0-dev

Available commands:

 build          Build a deployment package
 help           Get help for individual commands
 self-update    Updates zfdeploy.phar to the latest version
 version        Display the version of the script
```

The full syntax of the `build` command includes:

```console
Usage:
 build <package> [--target=] [--modules=] [--vendor|-v]:vendor [--composer=] [--gitignore=] [--deploymentxml=] [--zpkdata=] [--version=]

Arguments:
 <package>      Name of the package file to create; suffix must be .zip, .tar, .tar.gz, .tgz, or .zpk
 --target       The target directory of the application to package; defaults to current working directory
 --modules      Comma-separated list of modules to include in build
 --vendor|-v    Whether or not to include the vendor directory (disabled by default)
 --composer     Whether or not to execute composer; "on" or "off" ("on" by default)
 --gitignore    Whether or not to parse the .gitignore file to determine what files/folders to exclude; "on" or "off" ("on" by default)
 --configs      Path to directory containing application config files to include in the package
 --deploymentxmlPath to a custom deployment.xml to use when building a ZPK package
 --zpkdata      Path to a directory containing ZPK package assets (deployment.xml, logo, scripts, etc.)
 --version      Specific application version to use for a ZPK package
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

> ### Note: production configuration
>
> Zend Framework 2 applications often include `{,*.}local.php` files in `config\autoload/`, which
> are used to provide environment specific configuration. (In Apigility, this may include database
> configuration, Authentication configuration, etc.). These files are omitted from version control
> via `.gitignore` directives -- and, by default, from packaging.
>
> The settings you want for production will often differ from those in your development environment,
> and you may push them to production in a variety of ways -- via Chef, Puppet, Ansible, etc.
> Another option is to use the `--configs` flag when building your package. You can pass a directory
> containing production configuration files, and these will then be included in your deployment
> package.

Getting help
------------

The `help` command can list both the available commands, as well as provide the syntax for each
command:

- `zfdeploy help` will list all commands available.
- `zfdeploy help <command>` will show usage for the named command.
