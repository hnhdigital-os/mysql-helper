```
                           _        _          _
                          | |      | |        | |
 _ __ ___  _   _ ___  __ _| |______| |__   ___| |_ __   ___ _ __
| '_ ` _ \| | | / __|/ _` | |______| '_ \ / _ \ | '_ \ / _ \ '__|
| | | | | | |_| \__ \ (_| | |      | | | |  __/ | |_) |  __/ |
|_| |_| |_|\__, |___/\__, |_|      |_| |_|\___|_| .__/ \___|_|
            __/ |       | |                     | |
           |___/        |_|                     |_|
```

Provides a helper to sync, clone, and backup local & remote databases.

[![Latest Stable Version](https://img.shields.io/github/release/hnhdigital-os/mysql-helper.svg)](https://travis-ci.org/hnhdigital-os/mysql-helper) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT) [![Donate to this project using Patreon](https://img.shields.io/badge/patreon-donate-yellow.svg)](https://patreon.com/RoccoHoward)

[![Build Status](https://travis-ci.org/hnhdigital-os/mysql-helper.svg?branch=master)](https://travis-ci.org/hnhdigital-os/mysql-helper) [![StyleCI](https://styleci.io/repos/162653021/shield?branch=master)](https://styleci.io/repos/162653021) [![Test Coverage](https://codeclimate.com/github/hnhdigital-os/mysql-helper/badges/coverage.svg)](https://codeclimate.com/github/hnhdigital-os/mysql-helper/coverage) [![Issue Count](https://codeclimate.com/github/hnhdigital-os/mysql-helper/badges/issue_count.svg)](https://codeclimate.com/github/hnhdigital-os/mysql-helper) [![Code Climate](https://codeclimate.com/github/hnhdigital-os/mysql-helper/badges/gpa.svg)](https://codeclimate.com/github/hnhdigital-os/mysql-helper)

This package has been developed by H&H|Digital, an Australian botique developer. Visit us at [hnh.digital](http://hnh.digital).

## Requirements

* PHP 7.1.3 (min)
* zip
* pv
* mysql
* php-ssh2

## Installation

Run the installer to automatically download the latest version.

`bash <(curl -s https://hnhdigital-os.github.io/mysql-helper/install)`

Run the install command to run automatically dependency installation.
NOTE: This currently only works on Debian (uses apt-get). See above requirements to manually install.

`mysql-helper install`

Run the configure command to setup a profile, and add local and remote connections.

`mysql-helper configure`

## Updating

This tool provides a self-update mechanism. Simply run the self-update command.

`mysql-helper self-update`

## How to use

```
USAGE: mysql-helper <command> [options] [arguments]
  configure        Run the configuration wizard.
  self-update      Check if there is a new version and update.
  self-update      [--tag=?]
                   Update this binary to a specific tagged release.
  self-update      [--check-release=?]
                   Returns the current binary version.
```

## Contributing

Please see [CONTRIBUTING](https://github.com/hnhdigital-os/mysql-helper/blob/master/CONTRIBUTING.md) for details.

## Credits

* [Rocco Howard](https://github.com/RoccoHoward)
* [All Contributors](https://github.com/hnhdigital-os/mysql-helper/contributors)

## License

The MIT License (MIT). Please see [License File](https://github.com/hnhdigital-os/mysql-helper/blob/master/LICENSE.md) for more information.
