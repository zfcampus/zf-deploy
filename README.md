ZFDeploy - Command line tool to deploy ZF2 applications
=======================================================

This is a proof of concept of a deployment tool for ZF2 applications (including Apigility).


Execute the zfdeploy tool
-------------------------

You can execute the zfdeploy.php tool using the following command:

```bash
bin/zfdeploy.php <path> -o <filename>
```

where `<path>` is the root path of the ZF2 application to deploy and `<filename>` is the
filename of the output package to produce.

The tool supports different file format such as .zip, .tar, .tar.gz (or .tgz) and .zpk (the
deploy file format of [Zend Server 6](http://files.zend.com/help/Zend-Server-6/zend-server.htm#understanding_the_package_structure.htm)).

The syntax of the `zfdeploy.php` script includes also the following optional parameters:

```
-m <modules>        The list of modules to deploy, separated by comma (if empty deploy all)
-vendor             Include the vendor folder (not included by default)
-composer <on|off>  Determine if execute composer install (on by default)
-gitignore <on|off> Determine if parse the .gitignore to exclude file/folder (on by default)
-d <deploy.xml>     Specify the deployment.xml file to use for ZPK format (default in /data/deployment.xml)
```

