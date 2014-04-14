ZFDeploy - deploy ZF2 applications
==================================

**ZFDeploy** is a command line tool to deploy [Zend Framework 2](http://framework.zend.com) applications.

This tool produces a file package ready to be deployed. The tool supports the following format:
ZIP, TAR, TGZ (.TAR.GZ), .ZPK (the deployment file format of [Zend Server 6](http://files.zend.com/help/Zend-Server-6/zend-server.htm#understanding_the_package_structure.htm)).

Usage
-----

The command line tool is located in the `bin` folder and can be executed using the following command:

```bash
$ bin/zfdeploy.php <path> -o <filename>
```

where `<path>` is the root path of the ZF2 application to deploy and `<filename>` is the
filename of the output package to produce. You can specify the file format directly in the `<filename>`
using the proper extension (e.g. `application.zip` will create a ZIP file).

The full syntax of `zfdeploy.php` includes also the following optional parameters:

```bash
$ bin/zfdeploy.php <path> -o <filename> [-m <modules>] [-vendor] [-composer <on|off>] [-gitignore <on|off>] [-d <deploy.xml>] [-ver <version>]
```

where:

```
-m <modules>        The list of modules to deploy, separated by comma (if empty deploy all)
-vendor             Include the vendor folder (not included by default)
-composer <on|off>  Determine if execute composer install (on by default)
-gitignore <on|off> Determine if parse the .gitignore to exclude file/folder (on by default)
-d <deploy.xml>     Specify the deployment.xml file to use for ZPK format (default in /data/deployment.xml)
-ver <version>      Specify the application version to use for ZPK format (default is timestamp)
```

This deployment tool takes care of the local configuration files, related to the specific environment, using
the `.gitignore` file. If your applications use the `.gitignore` file to exclude local configuration files, for
instance the `local.php` file in the `/config/autoload` folder, **ZFdeploy** will not include these files
in the deployment package. You can disable the usage of the `.gitignore` file using the `-gitignore off` option.

> ### NOTE: if you disable the .gitignore usage
> 
> If you disable the `.gitignore` using the `-gitignore off` option, all the files of the ZF2 application will
> be included in the package. **That means local configuration files, including sensitive information like 
> database credentials, are deployed in production!!!** Please consider this behaviour before switch off the
> gitignore option.


Another important part of the deployment of a ZF2 application is the usage of [composer](https://getcomposer.org).

**ZFDeploy** executes the following composer command during the creation of the deployment package:

```bash 
$ php composer.phar install --no-dev --prefer-dist --optimize-autoloader 
```

The `--no-dev` flag ensures that development packages are not installed in the production environment.
The `--prefer-dist` option tell composer to install from dist if possible. This can speed up installs
substantially on build servers and other use cases where you typically do not run updates of the vendors
The `--optimize-autoloader` flag makes Composer's autoloader more performant by building a "class map".

For more information about Composer, you can read the [Documentation](https://getcomposer.org/doc/) page of the project.


