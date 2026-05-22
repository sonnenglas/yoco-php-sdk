# Error handling

A practical guide to the SDK's exception hierarchy: which exception you
get for which condition, which ones to retry, which ones to surface to
the user, and what to log.

## Why

Yoco can fail your request for half a dozen distinct reasons, and they
mean very different things. A `400` is your fault and will never succeed
no matter how often you retry; a `429` will succeed once you back off; a
`5xx` is Yoco's fault and you should retry with exponential backoff. The
SDK maps each into a distinct exception subclass so you do not have to
`grep` on status codes.

## The hierarchy

```text
RuntimeException
└── Sonnenglas\Yoco\Exceptions\YocoException        (abstract — never thrown directly)
    ├── ApiException                                (catch-all: anything that hit Yoco)
    │   ├── ValidationException                     (HTTP 400)
    │   ├── AuthenticationException                 (HTTP 401 / 403)
    │   ├── IdempotencyConflictException            (HTTP 409)
    │   ├── IdempotencyMismatchException            (HTTP 422)
    │   └── RateLimitException                      (HTTP 429, exposes $retryAfter)
    └── SignatureVerificationException              (webhook signature failed)
```

Every exception you can catch from this SDK extends `YocoException`. The
`InvalidArgumentException` thrown by the DTO constructors (e.g. when
`amount < 200` or `currency !== 'ZAR'`) is a plain PHP
`\InvalidArgumentException` and is not part of the Yoco hierarchy — it
is a programmer error, not a runtime API condition.

### `ApiException` (5xx, unmapped 4xx, transport errors)

The base class for anything that talked to Yoco's HTTP API and got back a
response the SDK could not turn into a more specific subclass. Surface
attributes:

```php
$e->statusCode    // int  — HTTP status, or 0 for transport errors
$e->responseBody  // array<string, mixed> — decoded Yoco error body
$e->getMessage()  // either Yoco's "message" field, or "HTTP <code> from Yoco API"
$e->getPrevious() // for transport errors, the underlying PSR-18 exception
```

Crucially: `getMessage()` **never** includes the request bytes, since
some PSR-18 clients embed the full outgoing request (with
`Authorization: Bearer sk_live_...`) in their exception messages. The
original exception is available via `getPrevious()` if you really need
it — at your own risk.

**Action.** For 5xx: retry with exponential backoff (max 3 attempts).
For unmapped 4xx: surface to the user. For transport errors
(`$e->statusCode === 0`): retry once, then surface.

### `ValidationException` (HTTP 400)

Your request body was wrong. Maybe a missing field, a malformed URL, a
metadata key that exceeds the maximum length, an `externalId` you have
already used. Yoco rejects the request before processing — no payment
attempt was created.

**Action.** **Do not retry.** Fix the request and re-call. Surface to the
user (or back to the calling code) with `$e->responseBody['message']` —
Yoco's error messages are usually self-explanatory.

### `AuthenticationException` (HTTP 401 / 403)

Two cases:

- `403` — the typical Yoco response when your secret key is wrong,
  revoked, or sent against the wrong environment (test key in production,
  live key in test).
- `401` — the SDK defends against the case where a proxy or CDN strips
  the API key before it reaches Yoco and returns `401` itself.

**Action.** **Do not retry.** Surface to operations. Verify the key is
present, has the right prefix (`sk_test_` vs `sk_live_`), and is loaded
correctly from your secret store.

### `IdempotencyConflictException` (HTTP 409)

You sent a request with the same `Idempotency-Key` as an in-flight
request that has not yet completed on Yoco's side. Almost always means
your code dispatched the same logical request twice in parallel.

**Action.** **Retry with a short delay.** A 100–500 ms jittered sleep is
typically enough. The original request will complete on Yoco's end and
your retry will see the stored response.

```php
use Sonnenglas\Yoco\Exceptions\IdempotencyConflictException;

$attempts = 0;
beginning:
try {
    $checkout = $client->checkouts()->create($request, idempotencyKey: $key);
} catch (IdempotencyConflictException $e) {
    if (++$attempts >= 3) {
        throw $e;
    }
    usleep(random_int(100_000, 500_000)); // 100ms–500ms jitter
    goto beginning;
}
```

### `IdempotencyMismatchException` (HTTP 422)

You reused an `Idempotency-Key` with a *different* request body. Yoco
rejects the call rather than silently accepting it (which would lose
data). This is a programming bug — your retry logic is generating the
same key for two different requests.

**Action.** **Do not retry.** Generate a fresh `Idempotency-Key` (or omit
it — the SDK will generate a UUID v4 for you). Log loudly: a recurring
mismatch points at a real bug in how you derive idempotency keys.

> Note: Yoco's Checkout API reserves 422 specifically for idempotency
> mismatches. For generic input validation errors it returns 400 (see
> `ValidationException` above).

### `RateLimitException` (HTTP 429)

You are sending requests faster than Yoco allows. The Checkout API does
not document explicit rate limits, but the SDK still maps `429` to a
distinct exception so you can react if proxies or CDNs upstream of Yoco
ever do impose one.

The exception exposes `$retryAfter` (seconds) parsed from the
`Retry-After` response header. It is `null` if the header was missing or
unparseable.

**Action.** Sleep `max($e->retryAfter ?? 1, 1)` seconds, then retry. Cap
total retries at 3 to avoid infinite loops.

```php
use Sonnenglas\Yoco\Exceptions\RateLimitException;

$delay = 1;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $checkout = $client->checkouts()->create($request);
        break;
    } catch (RateLimitException $e) {
        if ($attempt === 3) {
            throw $e;
        }
        $sleep = $e->retryAfter ?? $delay;
        sleep($sleep);
        $delay *= 2;
    }
}
```

### `SignatureVerificationException`

Thrown only from `SignatureVerifier::verify()`. The webhook was forged,
tampered with, expired, or comes from a different subscription's secret.
This is **not** a transient condition — Yoco will not re-sign it
correctly on retry.

**Action.** Respond `401`. Do **not** retry processing. Log the failure
loudly (count, alert on a spike). Never echo `$e->getMessage()` back to
the caller — leaking which check failed is a tiny side-channel that you
have no upside to giving away.

```php
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;

try {
    $event = $verifier->verify($rawBody, $headers);
} catch (SignatureVerificationException $e) {
    $logger->warning('Webhook signature failed', ['reason' => $e->getMessage()]);
    http_response_code(401);
    exit;
}
```

## Decision matrix

| Exception                          | HTTP   | Retry?              | Surface to user?           |
|------------------------------------|--------|---------------------|----------------------------|
| `ValidationException`              | 400    | **No.** Fix request | Yes (use `responseBody`)   |
| `AuthenticationException`          | 401/403| **No.** Fix key     | Operations only            |
| `IdempotencyConflictException`     | 409    | Yes (short jitter)  | Only if all retries fail   |
| `IdempotencyMismatchException`     | 422    | **No.** Fix key gen | Yes — it's a bug           |
| `RateLimitException`               | 429    | Yes (use `retryAfter`) | Only if all retries fail |
| `ApiException` (5xx, transport)    | 5xx/0  | Yes (exp backoff)   | After 3 attempts           |
| `ApiException` (other 4xx)         | other  | **No.**             | Yes                        |
| `SignatureVerificationException`   | —      | **No.**             | Respond `401`, log         |

## Strategy: catch wide, then narrow

The general pattern is to catch `YocoException` at the top of your domain
operation, and `match`/`switch` on the concrete subclass when you need
different behaviour:

```php
use Sonnenglas\Yoco\Exceptions\{
    ApiException,
    AuthenticationException,
    IdempotencyConflictException,
    RateLimitException,
    ValidationException,
    YocoException,
};

try {
    $checkout = $client->checkouts()->create($request);
} catch (ValidationException $e) {
    return response()->json(['error' => $e->getMessage()], 422);
} catch (AuthenticationException $e) {
    $logger->critical('Yoco auth failed — secret key broken?', ['code' => $e->statusCode]);
    return response()->json(['error' => 'Payment provider unavailable'], 503);
} catch (RateLimitException $e) {
    return response()->json(['error' => 'Please retry shortly'], 429)
        ->withHeaders($e->retryAfter !== null ? ['Retry-After' => (string) $e->retryAfter] : []);
} catch (IdempotencyConflictException) {
    // Should already have been retried at SDK level — if we got here, fail.
    return response()->json(['error' => 'Duplicate payment in progress'], 409);
} catch (ApiException $e) {
    $logger->error('Yoco API error', [
        'status' => $e->statusCode,
        'body'   => $e->responseBody,
    ]);
    return response()->json(['error' => 'Payment provider error'], 502);
} catch (YocoException $e) {
    // Fallback — should never hit if the catches above are exhaustive.
    $logger->error('Unhandled Yoco error', ['class' => $e::class, 'message' => $e->getMessage()]);
    return response()->json(['error' => 'Internal error'], 500);
}
```

## Logging best practices

- **Never log the secret key.** Not in plaintext, not in URL parameters,
  not as part of a serialized request object. The SDK already strips it
  from transport-error messages; do not undo that work by logging
  `$request` blobs.
- **Redact `responseBody` if you log it.** Most Yoco error bodies are
  safe, but log filters that catch on the word `secret` or
  `Authorization` are cheap insurance.
- **Tag logs with the Idempotency-Key.** When troubleshooting a duplicate
  charge or a stuck retry, the key is the single fastest way to correlate
  your application logs with Yoco's audit trail.
- **Count `SignatureVerificationException` separately.** A baseline of
  zero, with an alert on the first one, beats burying it inside a generic
  "exception" counter.

## Common pitfalls

- **Catching `\Exception` and assuming you handled Yoco.** You will silently
  catch unrelated runtime errors. Catch `YocoException` (or its concrete
  subclasses) explicitly.
- **Retrying `ValidationException`.** It will never succeed. Surface and
  fix.
- **Retrying `IdempotencyMismatchException`.** Same — fix your key
  generation, do not retry.
- **Ignoring `$retryAfter`.** Yoco asked you to wait. Honour the value or
  you will spin against the rate limiter.
- **Echoing `$e->getMessage()` back to the customer.** Some messages are
  fine to surface (`amount must be at least 200 cents`); some are
  internal noise. Curate the user-facing copy.
- **Treating `SignatureVerificationException` as a 500.** It is a 401 —
  the request failed authentication. A 500 invites Yoco to retry, which
  it should not.

## Next steps

- [Webhook handling](webhook-handling.md) — where the
  `SignatureVerificationException` path lives.
- [Testing](testing.md) — how to assert on every exception subclass
  without hitting the real API.
- [API reference: exceptions](../api/exceptions.md) — every property of
  every exception class.
