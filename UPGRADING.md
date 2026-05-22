# Upgrading

This document captures breaking changes between releases and the steps required
to migrate. See [`CHANGELOG.md`](CHANGELOG.md) for the full release history.

> Versions `0.1.0`–`0.3.0` were internal pre-release iterations and were never
> published to Packagist. The first publicly available release is `1.0.0`.

## Upgrading to 1.0.0

`1.0.0` is the first stable release and consolidates the `0.2.x` and `0.3.x`
internal iterations without further API changes. If you have been using
`^0.3.0` your code continues to work unchanged — just update the constraint:

```bash
composer require sonnenglas/yoco-php-sdk:^1.0
```

From `1.0.0` onward this package follows semantic versioning. Breaking changes
will only land in a new major (`2.0.0`); minor versions add backwards-compatible
features; patches fix bugs without changing the public API.

## Upgrading from 0.1.x to 0.2.x

`0.2.0` rebuilds the HTTP error mapping around the actual behaviour of the
Yoco Checkout API and adds refund support, `Idempotency-Key` handling, and a
handful of DTO field additions. It is a breaking release on `0.x` and therefore
requires explicit migration.

**Quick upgrade command:**

```bash
composer require sonnenglas/yoco-php-sdk:^0.2
```

### Breaking changes

#### 1. HTTP status code mapping reworked

Several status codes now map to different exception subclasses. The new mapping
reflects how the Yoco Checkout API actually responds, rather than a generic
REST convention.

| Status | Previously (`0.1.x`) | Now (`0.2.x`)                  |
|--------|----------------------|--------------------------------|
| `400`  | `ApiException`       | `ValidationException`          |
| `401`  | `AuthenticationException` (only) | `AuthenticationException` (defensive — Checkout API uses 403) |
| `403`  | _not mapped_         | `AuthenticationException`      |
| `409`  | `ApiException`       | `IdempotencyConflictException` |
| `422`  | `ApiException`       | `IdempotencyMismatchException` |
| `429`  | `RateLimitException` | `RateLimitException` (now exposes `$retryAfter`) |

**Important:** in the Yoco Checkout API, `422` is **not** a generic
validation-error code — it is specifically an `Idempotency-Key` mismatch
(re-using the same key with a different body). Generic body-validation errors
return `400`. Catch them separately.

**Before (`0.1.x`):**

```php
try {
    $client->checkouts()->create($request);
} catch (ApiException $e) {
    if ($e->statusCode === 400) {
        // handle validation
    }
    if ($e->statusCode === 422) {
        // assumed to be validation
    }
}
```

**After (`0.2.x`):**

```php
use Sonnenglas\Yoco\Exceptions\IdempotencyConflictException;
use Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException;
use Sonnenglas\Yoco\Exceptions\ValidationException;

try {
    $client->checkouts()->create($request);
} catch (ValidationException $e) {
    // 400 — bad request body
} catch (IdempotencyConflictException $e) {
    // 409 — another request with this key is in flight; retry after a delay
} catch (IdempotencyMismatchException $e) {
    // 422 — different body than the one originally stored under this key
}
```

All these exceptions still extend `ApiException`, so existing
`catch (ApiException $e)` blocks continue to work as a fallback.

#### 2. `WebhookSubscription::$mode` is now required

`WebhookSubscription` gained a required `string $mode` property (`live` / `test`).
If you instantiate the DTO directly (rather than through `Webhooks::create()` or
`Webhooks::list()`), you must add the new argument.

**Before:**

```php
new WebhookSubscription(
    id:     'wh_abc',
    name:   'production',
    url:    'https://example.com/hook',
    secret: 'whsec_...',
);
```

**After:**

```php
new WebhookSubscription(
    id:     'wh_abc',
    name:   'production',
    url:    'https://example.com/hook',
    mode:   'live',                       // new
    secret: 'whsec_...',
);
```

#### 3. `Webhooks::list()` now throws on malformed responses

In `0.1.x`, a response body that lacked the `subscriptions` key (or contained
malformed entries) was silently treated as an empty list. In `0.2.x` it raises
an `ApiException`, surfacing the real problem instead of hiding it.

If your code relied on the silent empty-list behaviour, wrap the call:

```php
try {
    $subscriptions = $client->webhooks()->list();
} catch (ApiException $e) {
    $subscriptions = [];           // restore the old behaviour explicitly
}
```

We recommend letting the exception propagate — a malformed `list()` response
is a real signal something is wrong upstream.

#### 4. `RateLimitException` constructor signature changed

A new last parameter `?int $retryAfter = null` was added.

**Before:**

```php
new RateLimitException('Too many requests', 429, $body, $previous);
```

**After:**

```php
new RateLimitException('Too many requests', 429, $body, $previous, $retryAfter);
```

Because the new parameter has a default value, calls that did not supply it
continue to compile and run. You only need to update if you were instantiating
`RateLimitException` by hand (most callers receive it from the SDK and never
construct it themselves).

The `$retryAfter` field is now populated automatically from the `Retry-After`
response header when the SDK throws this exception.

### Non-breaking additions

These do not require code changes but unlock new capabilities.

#### `Checkouts::create()` accepts an explicit Idempotency-Key

```php
$client->checkouts()->create($request, idempotencyKey: 'order-100-attempt-1');
```

Re-using the same key for retries makes the request safe to repeat — Yoco
returns the original response and does not create a duplicate checkout.

#### `Checkouts::refund()` (new)

```php
$refund = $client->checkouts()->refund(
    checkoutId:     'ch_9LVKD8GnAj7f39DFbn4F16bE',
    amount:         2500,                         // partial; null for full refund
    idempotencyKey: 'refund-order-100',
);
```

#### Additional `CheckoutResponse` fields

`CheckoutResponse` now parses four extra optional fields when Yoco returns
them:

- `paymentId` — the corresponding `p_…` payment id
- `processingMode` — `live` or `test`
- `merchantId` — your Yoco merchant id
- `clientReferenceId` — the value you passed as `externalId`

#### `SignatureVerifier` clock injection

The verifier now accepts an optional `?callable $clock` argument in its
constructor, returning the current Unix timestamp. This makes tests
deterministic and allows you to verify historical events against their
original signing time:

```php
$verifier = new SignatureVerifier($secret, clock: fn () => 1715000000);
```

#### `SignatureVerifier::verify()` validates `toleranceSeconds`

`toleranceSeconds` must now be between `0` and
`SignatureVerifier::MAX_TOLERANCE_SECONDS` (3600). Values outside the range
throw `InvalidArgumentException`. Previously, any integer was accepted, which
defeated the purpose of the tolerance window.

#### User-Agent header

Every outbound request now carries:

```
User-Agent: sonnenglas-yoco-php-sdk/<sdk-version> (PHP/<php-version>)
```

This helps Yoco's support team identify SDK-originated traffic.

#### Defensive size limits

- Response bodies over **1 MiB** raise `ApiException` rather than being parsed.
- Webhook bodies over **1 MiB** raise `SignatureVerificationException`.
- JSON decode depth is capped at **64** (was effectively unlimited).

If you hit these limits, you are almost certainly looking at a bug or a
misbehaving proxy rather than a legitimate Yoco response.
