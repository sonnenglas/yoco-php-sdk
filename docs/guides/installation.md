# Installation

This guide walks you through installing `sonnenglas/yoco-php-sdk` from scratch
and verifying that the install is healthy before you write any payment code.

## Why

The SDK is **framework-agnostic** and built against
[PSR-18 (HTTP client)](https://www.php-fig.org/psr/psr-18/),
[PSR-17 (HTTP factories)](https://www.php-fig.org/psr/psr-17/) and
[PSR-7 (HTTP messages)](https://www.php-fig.org/psr/psr-7/). That means you can
drop it into Laravel, Symfony, Slim, Mezzio, ReactPHP, a CLI script, or any
modern PHP project without bringing in a framework-coupled adapter.

The only required runtime dependency the SDK does not declare is a concrete
PSR-18 HTTP client — you pick the one that fits your stack.

## Requirements

- **PHP 8.2 or newer.** The SDK uses
  [readonly properties](https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.readonly-properties),
  [enums](https://www.php.net/manual/en/language.enumerations.php),
  [first-class callable syntax](https://wiki.php.net/rfc/first_class_callable_syntax)
  and [named arguments](https://www.php.net/manual/en/functions.arguments.php#functions.named-arguments).
- **A PSR-18 HTTP client.** [Guzzle 7](https://docs.guzzlephp.org/) is the
  recommended default. Any compliant client works.
- **Composer 2.x.** The package is autoload-only; no installer scripts run.

Check your PHP version:

```bash
php -v
```

If you see anything older than `8.2.0`, upgrade before continuing.

## How

### 1. Add the package to your project

```bash
composer require sonnenglas/yoco-php-sdk
```

This pulls in the SDK and the PSR contract packages (`psr/http-client`,
`psr/http-factory`, `psr/http-message`), plus `php-http/discovery`, which
auto-wires whichever concrete PSR-18 / PSR-17 implementation is installed.

To pin to the current major (recommended for production):

```bash
composer require sonnenglas/yoco-php-sdk:^1.0
```

From `1.0.0` onward the package follows [semantic versioning](https://semver.org/)
— breaking changes only land in a new major. See [`CHANGELOG.md`](../../CHANGELOG.md)
and [`UPGRADING.md`](../../UPGRADING.md) for details.

### 2. Install a PSR-18 client (if you do not already have one)

If your project does not yet depend on a PSR-18 client, install Guzzle:

```bash
composer require guzzlehttp/guzzle
```

`php-http/discovery` will detect it automatically — the SDK constructor needs
no extra configuration.

If you are unsure, run:

```bash
composer show | grep -E 'guzzle|http-client|symfony/http-client'
```

If you see any of:

- `guzzlehttp/guzzle`
- `symfony/http-client` with `nyholm/psr7`
- `kriswallsmith/buzz`
- `php-http/curl-client`

… discovery will work and you can skip the explicit `guzzle` install.

### 3. Verify the install

Create a tiny script — `verify-yoco.php` — and run it:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Sonnenglas\Yoco\Client;

$client = new Client(secretKey: 'sk_test_dummy_for_verification');

echo "SDK version:   " . Client::VERSION . PHP_EOL;
echo "API base URI:  " . $client->getBaseUri() . PHP_EOL;
echo "Install OK." . PHP_EOL;
```

```bash
php verify-yoco.php
```

Expected output:

```
SDK version:   1.0.0
API base URI:  https://payments.yoco.com/api
Install OK.
```

If you see a `NoCandidateFoundException` from `php-http/discovery`, you do not
yet have a PSR-18 client installed — go back to step 2.

If you see `Class "Sonnenglas\Yoco\Client" not found`, your autoloader is not
loaded — make sure the `require` line above points at the correct
`vendor/autoload.php`.

## Alternative HTTP clients

You only need to inject a custom client when you want behaviour the default
discovery cannot give you — for example a different timeout, a retry
middleware, a corporate proxy, or an in-memory mock in tests. Wire it
manually via the constructor:

### Guzzle with custom configuration

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Sonnenglas\Yoco\Client;

$guzzle = new GuzzleClient([
    'timeout'         => 10,
    'connect_timeout' => 3,
    'proxy'           => getenv('HTTP_PROXY') ?: null,
]);

$factory = new HttpFactory();

$client = new Client(
    secretKey:      getenv('YOCO_SECRET_KEY'),
    httpClient:     $guzzle,
    requestFactory: $factory,
    streamFactory:  $factory,
);
```

### Symfony HttpClient (PSR-18 bridge)

```bash
composer require symfony/http-client nyholm/psr7
```

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Sonnenglas\Yoco\Client;
use Symfony\Component\HttpClient\Psr18Client;

$psr17 = new Psr17Factory();

$client = new Client(
    secretKey:      getenv('YOCO_SECRET_KEY'),
    httpClient:     new Psr18Client(),
    requestFactory: $psr17,
    streamFactory:  $psr17,
);
```

### Buzz / php-http/curl-client / others

Any PSR-18 client paired with any PSR-17 factory works. Pass them in via the
named constructor arguments shown above.

## Next steps

- [Quickstart](quickstart.md) — build your first checkout in five minutes.
- [Webhook handling](webhook-handling.md) — receive payment confirmations.
- [Laravel integration](laravel-integration.md) — service container wiring.
- [Symfony integration](symfony-integration.md) — service definition snippets.
- [Plain PHP](plain-php.md) — Slim / vanilla PHP receiver examples.
