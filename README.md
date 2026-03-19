# MyAdmin Floating IPs Module

[![Tests](https://github.com/detain/myadmin-floating-ips-module/actions/workflows/tests.yml/badge.svg)](https://github.com/detain/myadmin-floating-ips-module/actions/workflows/tests.yml)
[![License: LGPL-2.1](https://img.shields.io/badge/License-LGPL%202.1-blue.svg)](https://opensource.org/licenses/LGPL-2.1)

Floating IP Services module for the [MyAdmin](https://github.com/detain/myadmin) control panel. Provides provisioning, activation, deactivation, and termination of floating IP address services with network switch integration via SSH.

## Features

- Floating IP allocation from configurable IP pools (IPv4 and IPv6)
- Automated network switch route management via SSH
- Full service lifecycle support: enable, reactivate, disable, terminate
- Symfony EventDispatcher integration for hook-based architecture
- Configurable billing, suspension, and deletion policies

## Requirements

- PHP >= 5.0
- Symfony EventDispatcher ^5.0
- MyAdmin Plugin Installer

## Installation

```bash
composer require detain/myadmin-floating-ips-module
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

This package is licensed under the [LGPL-2.1](LICENSE).
