# yoco-php-sdk

Framework-agnostic PHP SDK for the [Yoco](https://www.yoco.com/za/) Online (Checkout)
Payment API, with first-class support for Standard Webhooks signature verification.

[![Packagist Version](https://img.shields.io/packagist/v/sonnenglas/yoco-php-sdk.svg)](https://packagist.org/packages/sonnenglas/yoco-php-sdk)
[![Packagist Downloads](https://img.shields.io/packagist/dt/sonnenglas/yoco-php-sdk.svg)](https://packagist.org/packages/sonnenglas/yoco-php-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/sonnenglas/yoco-php-sdk.svg)](https://packagist.org/packages/sonnenglas/yoco-php-sdk)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon)

---

## Features

- **Framework-agnostic.** Plain PSR-18 (HTTP client), PSR-17 (factories), and PSR-7
  (messages). No Laravel, no Symfony, no global state — drop it into any modern
  PHP project.
- **Type-safe DTOs.** Readonly request/response objects with strict shape validation.
  No untyped arrays leaking through your code.
- **Idempotency built in.** `Checkouts::create()` and `::refund()` auto-generate a
  UUID v4 `Idempotency-Key` per call, or accept your own for deterministic retries.
- **Webhook signature verification.** Standard Webhooks compliant. Constant-time
  HMAC comparison, replay-window enforcement, multi-version signature parsing for
  graceful key rotation.
- **Hardened by default.** 1 MiB response and webhook body caps, JSON depth limit
  of 64, defensive header sanitization, and PSR-18 transport error wrapping that
  scrubs the `Authorization: Bearer` header from leaked messages.
- **Comprehensive HTTP error mapping.** `400`, `403`, `409`, `422`, `429` each map
  to a distinct exception subclass — no need to grep on status codes.
- **Auto-detected test mode.** `CheckoutResponse::$processingMode` and
  `WebhookSubscription::$mode` surface Yoco's `live` / `test` distinction so the
  same code path works in both environments.
- **PHPStan level 9.** Zero static-analysis errors. Strict types throughout.
- **98 tests.** PHPUnit 10 with realistic Yoco payload fixtures.

## Why this SDK?

Yoco does not publish an official PHP SDK. The only existing third-party package,
[`yoco/yoco-php-laravel`](https://github.com/yoco/yoco-php-laravel), has not been
updated in five years, is Laravel-specific, and predates the current Checkout API
entirely.

`sonnenglas/yoco-php-sdk` is built against the current public
[Yoco Online Payments API](https://developer.yoco.com/online/), targets modern
PHP (8.2+), and stays out of your dependency injection container by design.

## Requirements

- **PHP** 8.2 or newer.
- A **PSR-18** HTTP client. [Guzzle 7](https://docs.guzzlephp.org/) is the
  recommended default and is automatically discovered via
  [`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html).

## Installation

```bash
composer require sonnenglas/yoco-php-sdk
```

If you do not already have a PSR-18 client installed:

```bash
composer require guzzlehttp/guzzle
```

`php-http/discovery` will auto-wire any installed PSR-18 client and PSR-17
factories — you only need to inject them manually if you want to override
defaults (timeouts, retry middleware, mock client in tests, etc.).

## Quick start

### 1. Create a hosted checkout

```php
use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

$client = new Client(secretKey: getenv('YOCO_SECRET_KEY'));

$checkout = $client->checkouts()->create(new CreateCheckoutRequest(
    amount:     10000,                          // 100.00 ZAR — Yoco amounts are in cents
    currency:   'ZAR',
    successUrl: 'https://example.com/success',
    cancelUrl:  'https://example.com/cancel',
    metadata:   ['orderNumber' => 'ORD-100'],
));

header('Location: '.$checkout->redirectUrl);   // send the customer to Yoco
```

### 2. Verify an incoming webhook

```php
use Sonnenglas\Yoco\Webhook\SignatureVerifier;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;

$verifier = new SignatureVerifier(getenv('YOCO_WEBHOOK_SECRET')); // whsec_...

try {
    $event = $verifier->verify(file_get_contents('php://input'), getallheaders());
} catch (SignatureVerificationException $e) {
    http_response_code(401);
    exit;
}

// $event->type === 'payment.succeeded' | 'payment.failed'
// $event->payload['metadata']['orderNumber']
```

### 3. Refund a checkout

```php
$refund = $client->checkouts()->refund(
    checkoutId: 'ch_9LVKD8GnAj7f39DFbn4F16bE',
    amount:     2500,         // partial refund of 25.00 ZAR; omit for full refund
);

echo $refund->status;         // 'created' | 'succeeded' | ...
```

## Documentation

- [Documentation home](docs/README.md)
- **Guides**
  - [Installation](docs/guides/installation.md)
  - [Quickstart](docs/guides/quickstart.md)
  - [Webhook handling](docs/guides/webhook-handling.md)
  - [Signature verification (deep dive)](docs/guides/signature-verification.md)
  - [Error handling](docs/guides/error-handling.md)
  - [Testing your integration](docs/guides/testing.md)
  - [Laravel integration](docs/guides/laravel-integration.md)
  - [Symfony integration](docs/guides/symfony-integration.md)
  - [Plain PHP / Slim](docs/guides/plain-php.md)
- **API reference**
  - [Overview](docs/api/README.md)
  - [`Client`](docs/api/client.md)
  - [`Resources\Checkouts`](docs/api/checkouts.md)
  - [`Resources\Webhooks`](docs/api/webhooks.md)
  - [`Webhook\SignatureVerifier`](docs/api/signature-verifier.md)
  - [DTOs](docs/api/dtos.md)
  - [Exceptions](docs/api/exceptions.md)
- **Runnable examples** — see [`examples/`](examples/)

## Supported features

| In scope                                                  | Out of scope                                            |
|-----------------------------------------------------------|---------------------------------------------------------|
| `POST /api/checkouts` — create checkout                   | `GET /v1/payments/{id}` (main Yoco API)                 |
| `POST /api/checkouts/{id}/refund` — full + partial refund | Payouts (`/v1/payouts`)                                 |
| `POST /api/webhooks` — register subscription              | Locations (`/v1/locations`)                             |
| `GET /api/webhooks` — list subscriptions                  | OAuth applications                                      |
| `DELETE /api/webhooks/{id}` — delete subscription         | Yoco Card Machine / POS APIs                            |
| Standard Webhooks signature verification (`v1`)           | In-person card-present payments                         |
| Standard Webhooks key rotation (multi-signature headers)  |                                                         |
| Test-mode detection (`processingMode`, `mode` fields)     |                                                         |
| `Idempotency-Key` auto-generation (UUID v4)               |                                                         |
| `Retry-After` parsing on rate-limit responses             |                                                         |

The SDK targets the [Yoco Online Payments API](https://developer.yoco.com/online/)
at `https://payments.yoco.com/api`. The broader `api.yoco.com/v1` surface (payments,
payouts, locations) is intentionally not implemented — open an issue if you need it.

## Exceptions

All SDK exceptions extend `Sonnenglas\Yoco\Exceptions\YocoException`.

| HTTP   | Exception                            | When                                                              |
|--------|--------------------------------------|-------------------------------------------------------------------|
| 400    | `ValidationException`                | Invalid request body or parameters.                               |
| 401    | `AuthenticationException`            | Defensive — Checkout API uses 403, but proxies may return 401.    |
| 403    | `AuthenticationException`            | Missing or invalid API key.                                       |
| 409    | `IdempotencyConflictException`       | Another request with the same `Idempotency-Key` is in flight.     |
| 422    | `IdempotencyMismatchException`       | Re-used `Idempotency-Key` with a different request body.          |
| 429    | `RateLimitException` (`$retryAfter`) | Rate limit reached (defensive; surfaces `Retry-After` if present).|
| other  | `ApiException`                       | Anything else (5xx, unmapped 4xx, malformed JSON, etc.).          |
| —      | `SignatureVerificationException`     | Webhook signature failed verification.                            |

## Security

- **Constant-time signature comparison** via `hash_equals` — no early-exit timing
  side channel.
- **`WebhookSubscription` redaction** — `__debugInfo()` masks the webhook secret
  so it never surfaces in `var_dump`, `print_r`, or Symfony VarDumper output.
- **Transport-error sanitization** — PSR-18 client exceptions are wrapped without
  propagating the inner message, which on some clients embeds the full outgoing
  request (including the `Authorization: Bearer <secret>` header). The original
  exception is still available via `getPrevious()`.
- **Defensive size limits** — 1 MiB on response bodies, 1 MiB on webhook bodies,
  JSON depth capped at 64. Malformed or oversized payloads fail loudly rather
  than blow up memory.
- **Replay protection** — webhook timestamps are checked against a configurable
  tolerance window (default 180 s, max 3600 s).

See [SECURITY.md](SECURITY.md) for the full security policy and reporting
process.

## Development

```bash
composer install
composer test         # PHPUnit
composer phpstan      # PHPStan level 9
composer cs-fix       # PHP-CS-Fixer
composer check        # phpstan + test together
```

## Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

- See [CHANGELOG.md](CHANGELOG.md) for the release history.
- See [UPGRADING.md](UPGRADING.md) for breaking-change migration guides.

## Contributing

Pull requests, issues, and discussions are welcome. Please read
[CONTRIBUTING.md](CONTRIBUTING.md) before opening a PR — it covers the dev setup,
coding standards, and the test-first workflow used throughout the codebase.

For bug reports and feature requests, please use the GitHub issue templates.

## License

Released under the [MIT License](LICENSE).

## Credits

Built and maintained by [SONNENGLAS](https://www.sonnenglas.net/).

If this SDK saves you time, please consider starring the
[GitHub repository](https://github.com/sonnenglas/yoco-php-sdk).
