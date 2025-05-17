# PHP FastCGI Client

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
![Testing][ico-tests]
![Coverage Status][ico-coverage]
[![Total Downloads][ico-downloads]][link-packagist]

A modern, fully compliant FastCGI client for PHP that allows sending PSR-7 HTTP requests directly to FastCGI applications like PHP-FPM. The library supports request multiplexing, provides a Promise-based API using Guzzle Promises, and automatically converts FastCGI responses into PSR-7 Responses.

## Requirements

* PHP >= 7.2

## 🎯 Use Cases

- Talk directly to PHP-FPM without an HTTP server (e.g., from a custom gateway).
- Benchmark or test PHP-FPM pools under load.
- Run end-to-end tests with PHPUnit in a much faster way (no HTTP yet still PSR-7 responses).

## 📖 Story

This library came out of the need to perform end-to-end tests in the fastest possible way. My main goal was to get outside the app in order to make assertions.

We could say it fills the gap between browser-based testing tools like Selenium (slow) and solutions like Symfony's KernelTestCase, which doesn't go outside the app yet it makes it feel like it did (very fast).

By communicating directly with PHP's FastCGI interface via socket connections, it provides true end-to-end feedback with exceptional performance, which allows developers to test their applications from the outside while maintaining the speed needed for efficient test-driven development. 

Additionally, the implementation leverages modern tools like Guzzle Promises for asynchronous processing and standardised PSR-7 responses for seamless integration with your existing knowledge.

The documentation in the code serves both as a guide for developers making improvements and as an educational resource for those wanting to learn how the [FastCGI protocol](https://fastcgi-archives.github.io/FastCGI_Specification.html) works, turning a hard-to-understand code of a low-level protocol implementation into something understandable and maintainable.

## Installation

This package is installable and autoloadable via Composer as [filisko/php-fastcgi-client](https://packagist.org/packages/filisko/php-fastcgi-client).

```sh
composer require filisko/php-fastcgi-client
```

## Usage

TODO

---

## License and Contribution

Please see [CHANGELOG](CHANGELOG.md) for more information about recent changes and [CONTRIBUTING](CONTRIBUTING.md) for contributing details.

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

[ico-version]: https://img.shields.io/packagist/v/filisko/php-fastcgi-client.svg?style=flat
[ico-license]: https://img.shields.io/badge/license-MIT-informational.svg?style=flat
[ico-tests]: https://github.com/filisko/php-fastcgi-client/workflows/testing/badge.svg
[ico-coverage]: https://coveralls.io/repos/github/filisko/php-fastcgi-client/badge.svg?branch=main
[ico-downloads]: https://img.shields.io/packagist/dt/filisko/php-fastcgi-client.svg?style=flat

[link-packagist]: https://packagist.org/packages/filisko/php-fastcgi-client

