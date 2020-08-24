# Magento Installer


## Installation
```shell script
$ composer require shiftenterdev/magento-installer -g
```
Now confirm that vendor/bin directory is your $PATH. So that magento executable will found in your system.

> macOS: $HOME/.composer/vendor/bin \
> Linux OS: $HOME/.config/composer/vendor/bin or $HOME/.composer/vendor/bin \
> Windows: %USERPROFILE%\AppData\Roaming\Composer\vendor\bin 

```shell script
$ magento new <Folder Name> <Version>
#example
$ magento new demo_shop 2.3.0
#or
$ magento new demo_shop 2.3.5-p1
```


## Contributing

Thank you for considering contributing to the Installer!