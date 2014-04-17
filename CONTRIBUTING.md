# CONTRIBUTING

## RESOURCES

- Coding Standards:
  https://github.com/zendframework/zf2/wiki/Coding-Standards
- ZF Contributor's mailing list:
  Archives: http://zend-framework-community.634137.n4.nabble.com/ZF-Contributor-f680267.html
  Subscribe: zf-contributors-subscribe@lists.zend.com
- IRC:
  #zftalk.dev on Freenode.net

## INSTALLING DEPENDENCIES

`zf-deploy` uses [Composer](https://getcomposer.org/) for dependency management.
Execute the following to install them:

```console
$ composer.phar install
```

## BUILDING THE PHAR

`zf-deploy` uses [Box](http://box-project.org/) to build the `zfdeploy.phar`
file. Install Box globally using:

```console
$ composer.phar global rquire 'kherge/box=~2.4' --prefer-source
```

Once installed, rebuild the phar using:

```console
$ box build
```
