# Exceptions

> Every exception thrown by the SDK descends from `Sonnenglas\Yoco\Exceptions\YocoException`. Catch that single class for blanket handling, or catch sub-types for precise control.

## Hierarchy

```
\RuntimeException
└── Sonnenglas\Yoco\Exceptions\YocoException                 (abstract)
    ├── Sonnenglas\Yoco\Exceptions\ApiException              (statusCode, responseBody)
    │   ├── Sonnenglas\Yoco\Exceptions\AuthenticationException
    │   ├── Sonnenglas\Yoco\Exceptions\ValidationException
    │   ├── Sonnenglas\Yoco\Exceptions\IdempotencyConflictException
    │   ├── Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException
    │   └── Sonnenglas\Yoco\Exceptions\RateLimitException    (retryAfter)
    └── Sonnenglas\Yoco\Exceptions\SignatureVerificationException
```

In addition, the resources can throw `\InvalidArgumentException` from the
SDK itself (not from Yoco) — for example when you pass an empty
`checkoutId` to `refund()` or an out-of-range `toleranceSeconds` to the
verifier. These do not inherit from `YocoException`; they are programmer
errors, not remote-API errors.

---

## YocoException

```php
namespace Sonnenglas\Yoco\Exceptions;

abstract class YocoException extends \RuntimeException
```

The abstract root of the SDK's exception tree. Catch this if you want a
single `catch` block for "anything from the Yoco SDK", including signature
verification failures.

You cannot instantiate `YocoException` directly.

---

## ApiException

```php
namespace Sonnenglas\Yoco\Exceptions;

class ApiException extends YocoException
{
    public readonly int $statusCode;
    /** @var array<string, mixed> */
    public readonly array $responseBody;
}
```

Base class for **all** errors originating from an HTTP call to Yoco — both
the documented status-mapped sub-classes and unmapped or transport-level
problems.

### When is it thrown directly (not via a subclass)?

- Yoco returned a 4xx/5xx that does not match `400`, `401`, `403`, `409`,
  `422`, or `429`. The message defaults to Yoco's `message` field on the
  payload, or `"HTTP {statusCode} from Yoco API"` if absent.
- Yoco returned `200` but the body is not valid JSON, exceeds `MAX_RESPONSE_BYTES`
  (1 MiB), or cannot be parsed into the expected DTO (`fromArray()` failures).
  In these cases `statusCode` is the HTTP status (or `0` if the failure is
  not HTTP-side).
- A PSR-18 transport error occurred (DNS, TCP, TLS, timeout). The original
  exception is preserved via `getPrevious()`; the message does **not**
  embed the underlying exception's message, because some PSR-18 clients
  include the full outgoing request (with `Authorization: Bearer …`) in
  their text. `statusCode` is `0` in this case.

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `$statusCode` | `int` | HTTP status code, or `0` for transport errors and request-encoding errors. |
| `$responseBody` | `array<string, mixed>` | Decoded JSON body when available, otherwise `[]`. |

### Catching pattern

```php
use Sonnenglas\Yoco\Exceptions\ApiException;

try {
    $client->checkouts()->create($request);
} catch (ApiException $e) {
    $logger->error('Yoco API call failed', [
        'status' => $e->statusCode,
        'body'   => $e->responseBody,
        'message'=> $e->getMessage(),
    ]);
    throw $e;
}
```

---

## AuthenticationException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class AuthenticationException extends ApiException
```

**Thrown for HTTP 401 and HTTP 403.**

- `403` is the documented status the Yoco Checkout API returns when the
  `Authorization: Bearer` header is missing or the secret is invalid.
- `401` is a defensive catch — the Checkout API does not document a 401,
  but proxies and CDNs sometimes generate one.

`$statusCode` will be `401` or `403`. `$responseBody` contains Yoco's
error payload (when not mangled by a proxy).

**Action:** verify `YOCO_SECRET_KEY`, ensure you're using the right key for
the right environment (`sk_test_*` vs `sk_live_*`), check for accidental
whitespace in the value.

---

## ValidationException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class ValidationException extends ApiException
```

**Thrown for HTTP 400** — request validation rejected by Yoco.

`$statusCode === 400`. `$responseBody` typically contains Yoco's structured
validation details (field name, error message). Inspect the body to surface
a user-visible error.

**Important:** Yoco does **not** use HTTP 422 for body validation in the
Checkout API. 422 in this SDK is mapped to `IdempotencyMismatchException`.
See the [status mapping table](#http-status-mapping) below.

---

## IdempotencyConflictException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class IdempotencyConflictException extends ApiException
```

**Thrown for HTTP 409** — another request with the **same** `Idempotency-Key`
is still being processed by Yoco.

`$statusCode === 409`.

**Action:** retry after a short delay. The original request's result will
become available once Yoco finishes processing it; sending the same
idempotent request again is safe. Exponential backoff (e.g. `200ms`, `500ms`,
`1s`, `2s`) is appropriate.

---

## IdempotencyMismatchException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class IdempotencyMismatchException extends ApiException
```

**Thrown for HTTP 422** — you reused an `Idempotency-Key` with a **different**
request body (or method, or path) compared to the original request stored
under that key.

`$statusCode === 422`.

**Action:** this is **not** retryable as-is. Either:

1. Generate a fresh `Idempotency-Key` and re-send (creates a new operation);
   or
2. Re-send the exact original payload that produced the key (recovers the
   original result).

In production this usually indicates a key-collision bug on your side —
two distinct operations were given the same idempotency key.

---

## RateLimitException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class RateLimitException extends ApiException
{
    public readonly ?int $retryAfter;
}
```

**Thrown for HTTP 429.** Yoco's Checkout API does not currently document
rate limiting, but other Yoco APIs and most proxies / CDNs may emit `429`,
so the SDK maps it defensively.

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `$retryAfter` | `?int` | Number of seconds to wait before retrying, parsed from the `Retry-After` response header. Accepts both integer-seconds and the RFC 7231 HTTP-date form (e.g. `Wed, 21 Oct 2026 07:28:00 GMT`). An HTTP-date in the past yields `0` (retry immediately). `null` only when the header is missing or unparseable. |

### Catching pattern

```php
use Sonnenglas\Yoco\Exceptions\RateLimitException;

try {
    $client->checkouts()->create($request);
} catch (RateLimitException $e) {
    $wait = $e->retryAfter ?? 5;
    sleep($wait);                            // or schedule via queue
    // …retry…
}
```

---

## SignatureVerificationException

```php
namespace Sonnenglas\Yoco\Exceptions;

final class SignatureVerificationException extends YocoException
```

Thrown exclusively by `Webhook\SignatureVerifier::verify()`. Indicates that
an inbound webhook request **did not** pass verification and must be
rejected with `HTTP 401`.

This class does **not** descend from `ApiException` — there is no HTTP
status code or response body, only a message describing the failure.

The error message is one of a small set of strings (see the
[`SignatureVerifier` reference](signature-verifier.md#verifyrawbody-headers-toleranceseconds--180)
for the full list).

### Catching pattern

```php
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;

try {
    $event = $verifier->verify($rawBody, $headers);
} catch (SignatureVerificationException $e) {
    $logger->warning('Webhook signature invalid', ['reason' => $e->getMessage()]);
    http_response_code(401);
    exit;
}
```

---

## Catching strategies

### Blanket catch — anything from the SDK

```php
use Sonnenglas\Yoco\Exceptions\YocoException;

try {
    $client->checkouts()->create($request);
} catch (YocoException $e) {
    $logger->error('Yoco SDK failure', ['exception' => $e]);
    throw $e;
}
```

### Granular catch — separate handling per failure mode

```php
use Sonnenglas\Yoco\Exceptions\AuthenticationException;
use Sonnenglas\Yoco\Exceptions\IdempotencyConflictException;
use Sonnenglas\Yoco\Exceptions\RateLimitException;
use Sonnenglas\Yoco\Exceptions\ValidationException;
use Sonnenglas\Yoco\Exceptions\ApiException;

try {
    $response = $client->checkouts()->create($request, idempotencyKey: $key);
} catch (AuthenticationException) {
    alert_oncall('Yoco key is invalid');
    throw;
} catch (ValidationException $e) {
    $userVisible = $e->responseBody['message'] ?? 'Invalid checkout';
    return back()->withError($userVisible);
} catch (IdempotencyConflictException) {
    return retry_after(200);
} catch (RateLimitException $e) {
    return retry_after(($e->retryAfter ?? 5) * 1000);
} catch (ApiException $e) {
    $logger->error('Yoco unexpected', ['status' => $e->statusCode]);
    throw $e;
}
```

### Webhook receivers — always handle separately

Signature failures and downstream processing errors are conceptually
different — the first means "this isn't really from Yoco", the second means
"it's from Yoco but my code couldn't handle it". Use distinct catch blocks
so a buggy handler never silently masquerades as a forged webhook:

```php
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;

try {
    $event = $verifier->verify($rawBody, $headers);
} catch (SignatureVerificationException) {
    http_response_code(401);
    exit;
}

try {
    handle($event);
} catch (\Throwable $e) {
    $logger->error('Webhook handler failed', ['exception' => $e, 'event' => $event->id]);
    http_response_code(500);                // Yoco will retry the delivery
    exit;
}
```

---

## HTTP status mapping

| HTTP status | Exception class | Notes |
|-------------|-----------------|-------|
| `400` | `ValidationException` | Request body / parameter validation failure. |
| `401` | `AuthenticationException` | Defensive — Checkout API does not return 401, but proxies might. |
| `403` | `AuthenticationException` | Documented response for missing/invalid API key. |
| `409` | `IdempotencyConflictException` | Same `Idempotency-Key` already in flight. |
| `422` | `IdempotencyMismatchException` | **Not** a body-validation error — see note below. |
| `429` | `RateLimitException` (with `retryAfter`) | Defensive — Checkout API does not document 429. |
| other 4xx / 5xx | `ApiException` | Includes 404, 500, 502, 503 etc. |
| (no HTTP — transport) | `ApiException` (`statusCode = 0`) | PSR-18 client failed (DNS / TCP / TLS / timeout). Original error on `getPrevious()`. |
| (no HTTP — webhook) | `SignatureVerificationException` | Inbound webhook verification failed. |

**Important — 422 is not validation in Yoco's Checkout API.** Many REST APIs
use 422 for unprocessable-entity / body validation. Yoco's Checkout API
reserves 422 for the case where a previously-used `Idempotency-Key` is
sent with a different body. Generic body validation errors return 400. The
SDK reflects this — do not try-catch `ValidationException` to handle 422.

## See also

- [`Resources\Checkouts`](checkouts.md) — methods that emit these exceptions.
- [`Resources\Webhooks`](webhooks.md) — same.
- [`Webhook\SignatureVerifier`](signature-verifier.md) — emits
  `SignatureVerificationException`.
- [Error handling guide](../guides/error-handling.md) — recommended retry
  / backoff patterns.
