# Testing your Yoco integration

How to test code that uses this SDK, without ever calling a real Yoco
endpoint or charging a real card.

## Why

Yoco's test environment is excellent for end-to-end "does my checkout
button work" smoke tests — but it is the wrong tool for unit tests. You
do not want your test suite to:

- Depend on a network connection.
- Race against your CI quota.
- Generate test transactions you then have to clean up.
- Fail when Yoco is having a bad afternoon.

This guide covers both halves of testing: the high-confidence end-to-end
test that uses Yoco's sandbox, and the fast, deterministic unit test that
mocks the HTTP layer entirely.

## Test mode

Yoco distinguishes test and live by the **secret key prefix**:

- `sk_test_*` → all calls are simulated. No card is ever charged.
- `sk_live_*` → real payments.

The SDK does not need to know which one you used; the prefix routes
everything inside Yoco. You can confirm you are in test mode by inspecting
the response:

```php
$checkout = $client->checkouts()->create($request);

if ($checkout->processingMode !== 'test') {
    throw new RuntimeException('Expected test mode, got: ' . $checkout->processingMode);
}
```

`processingMode` is `'test'` or `'live'`; same convention applies to
`WebhookSubscription::$mode` (without the `processing` prefix).

### Test cards

Use Yoco's documented test cards when paying via the redirect URL:

| Card                  | Number               | Outcome                       |
|-----------------------|----------------------|-------------------------------|
| Visa — approved       | `4111 1111 1111 1111`| Always succeeds.              |
| Mastercard — approved | `5555 5555 5555 4444`| Always succeeds.              |
| Visa — declined       | `4000 0000 0000 0002`| Always declines.              |

Any future expiry month/year and any 3-digit CVC are accepted. Full,
current list including 3-D Secure flows and network-specific scenarios is
at [developer.yoco.com/docs/checkout-api/testing](https://developer.yoco.com/docs/checkout-api/testing).

> **Card declined as `"not a valid test card"`?** This is almost always a
> merchant-account configuration issue on Yoco's side (test acquirer not
> enabled, or the merchant signed up before test cards were turned on), not
> an SDK bug. Verify with `webhooks()->list()` that your `sk_test_*` key has
> API access, then ask Yoco support to enable test acquiring for your account.
> Yoco's shared developer test keys (documented at
> `developer.yoco.com/docs/checkout-api/testing`) always work and are a good
> sanity check.

### Constraints of test mode

- **Test transactions do not appear in the live dashboard.** Toggle the
  dashboard into Test mode (top-right) to see them, or call
  `webhooks()->list()` and filter on `$sub->mode === 'test'`.
- **Refunds are not supported in test mode.** Calling
  `$client->checkouts()->refund(...)` with an `sk_test_*` key will return
  an error. To exercise the refund path in tests, mock the HTTP client
  (see below).

## Mocking the HTTP client

For unit tests, swap the real PSR-18 client for an in-memory fake. The
SDK already lists `php-http/mock-client` as a dev dependency, so you have
the building block to hand:

```bash
composer require --dev php-http/mock-client nyholm/psr7
```

Then in your test:

```php
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

final class YocoCheckoutTest extends TestCase
{
    public function testCreateCheckoutParsesResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(
            status: 201,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'id'              => 'ch_TEST_abc123',
                'redirectUrl'     => 'https://pay.yoco.com/r/abc123',
                'status'          => 'created',
                'amount'          => 5000,
                'currency'        => 'ZAR',
                'paymentId'       => 'pay_TEST_xyz',
                'processingMode'  => 'test',
                'merchantId'      => 'mer_TEST_001',
            ], JSON_THROW_ON_ERROR),
        ));

        $factory = new Psr17Factory();
        $client = new Client(
            secretKey:      'sk_test_dummy',
            httpClient:     $mock,
            requestFactory: $factory,
            streamFactory:  $factory,
        );

        $response = $client->checkouts()->create(new CreateCheckoutRequest(
            amount:     5000,
            currency:   'ZAR',
            successUrl: 'https://example.test/ok',
            cancelUrl:  'https://example.test/cancel',
        ));

        self::assertSame('ch_TEST_abc123', $response->id);
        self::assertSame('test', $response->processingMode);

        // Inspect what the SDK actually sent.
        $request = $mock->getLastRequest();
        self::assertSame('Bearer sk_test_dummy', $request->getHeaderLine('Authorization'));
        self::assertNotSame('', $request->getHeaderLine('Idempotency-Key'));
    }
}
```

This pattern works for every endpoint — pre-load the mock with whichever
JSON shape you want to assert against, run the SDK call, and the mock
records the outgoing request for inspection.

### Asserting on error paths

To test how your code handles a `RateLimitException`:

```php
$mock->addResponse(new Response(
    status: 429,
    headers: ['Retry-After' => '5'],
    body: json_encode(['message' => 'Too many requests']),
));

$this->expectException(RateLimitException::class);

try {
    $client->checkouts()->create($validRequest);
} catch (RateLimitException $e) {
    self::assertSame(5, $e->retryAfter);
    throw $e;
}
```

`ValidationException` (400), `AuthenticationException` (401/403),
`IdempotencyConflictException` (409), `IdempotencyMismatchException` (422),
and `ApiException` (anything else) are all reachable the same way — return
the right status code and the SDK maps to the right exception subclass.

## Simulating webhook delivery in tests

For your **own** webhook receiver (the route that calls `verify()`), you
do not need a real Yoco delivery. You can sign a payload locally with the
same secret you would use in production:

```php
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

/**
 * Build a valid (id, timestamp, signature) triple for a given body.
 *
 * @return array{0: string, 1: string, 2: string}  [id, timestamp, signatureHeader]
 */
function signWebhook(string $secret, string $rawBody, ?int $timestamp = null): array
{
    if (! str_starts_with($secret, 'whsec_')) {
        throw new InvalidArgumentException('Secret must start with whsec_');
    }

    $id = 'msg_' . bin2hex(random_bytes(8));
    $ts = (string) ($timestamp ?? time());

    $rawSecret = base64_decode(substr($secret, strlen('whsec_')), true);
    $signed    = $id . '.' . $ts . '.' . $rawBody;
    $sig       = base64_encode(hash_hmac('sha256', $signed, $rawSecret, true));

    return [$id, $ts, 'v1,' . $sig];
}
```

Wire it into a test that drives your endpoint end-to-end:

```php
public function testReceiverMarksOrderPaid(): void
{
    $secret = 'whsec_' . base64_encode(random_bytes(32));

    $body = json_encode([
        'id'          => 'evt_test_001',
        'type'        => 'payment.succeeded',
        'createdDate' => '2026-05-01T12:00:00Z',
        'payload'     => [
            'id'       => 'pay_test_001',
            'amount'   => 5000,
            'currency' => 'ZAR',
            'metadata' => ['orderNumber' => 'ORD-100'],
        ],
    ], JSON_THROW_ON_ERROR);

    [$id, $ts, $sigHeader] = signWebhook($secret, $body);

    // Drive the endpoint under test however your framework supports it —
    // Symfony's KernelBrowser, Laravel's $this->postJson, Slim's
    // $app->handle(), or a vanilla PSR-7 request through your middleware.
    $response = $this->postWebhook(
        path:    '/webhooks/yoco',
        rawBody: $body,
        headers: [
            'webhook-id'        => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => $sigHeader,
        ],
    );

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('paid', Order::find('ORD-100')->status);
}
```

### Hard-coded signature vector

For regression tests that catch a refactor breaking your signing path,
pin a *fixed* secret, body, and timestamp and assert on the *exact*
signature bytes. If anything in the verifier ever drifts away from Yoco's
implementation, this test will fail loudly:

```php
public function testKnownGoodSignatureVector(): void
{
    $secret    = 'whsec_MTIzNDU2Nzg5MGFiY2RlZg=='; // base64("1234567890abcdef")
    $body      = '{"id":"evt_x","type":"payment.succeeded","createdDate":"2026-01-01T00:00:00Z","payload":{}}';
    $id        = 'msg_test';
    $timestamp = '1735689600';

    [, , $sigHeader] = signWebhook($secret, $body, (int) $timestamp);
    // Re-compute manually:
    $rawSecret = base64_decode(substr($secret, strlen('whsec_')), true);
    $expected  = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $rawSecret, true));

    self::assertSame(
        'v1,' . $expected,
        sprintf('v1,%s', $expected),
        'Locally-recomputed HMAC drifted — the verifier no longer matches the documented algorithm.',
    );
}
```

(The example above asserts trivially; in your real test you would compare
the *output of your code under test* against a hard-coded literal. Pick
one body + secret + timestamp combo and freeze the expected signature.)

## Injecting a fixed clock

`SignatureVerifier` accepts an optional `clock` callable. Useful for:

- Testing the tolerance boundary (`abs(now - timestamp) == tolerance`)
  without `usleep()`.
- Replaying historical webhook payloads where the original timestamp is
  far in the past.
- Property-based tests around clock drift.

```php
$verifier = new SignatureVerifier(
    secret: $secret,
    clock:  static fn (): int => 1_700_000_000,
);

// Sign with timestamp 1_700_000_000 — verifies.
// Sign with timestamp 1_699_999_500 (-500s) — fails (default tolerance 180s).
```

In tests that need to drive several different "now" values, wrap the
clock in a mutable closure:

```php
$now = 1_700_000_000;
$verifier = new SignatureVerifier(
    secret: $secret,
    clock:  static function () use (&$now): int { return $now; },
);

// Advance simulated time:
$now += 60;
```

## What about end-to-end tests?

A handful of "the storefront actually works against Yoco" tests is worth
having — but keep them small and isolate them from your fast unit suite:

1. Use a dedicated test-mode secret key, never your live key.
2. Tag the tests with a PHPUnit group (e.g. `@group e2e-yoco`) and exclude
   them from the default run via `phpunit.xml`:
   ```xml
   <testsuites>
     <testsuite name="default">
       <directory>tests/Unit</directory>
       <directory>tests/Feature</directory>
       <exclude>tests/E2E</exclude>
     </testsuite>
     <testsuite name="e2e">
       <directory>tests/E2E</directory>
     </testsuite>
   </testsuites>
   ```
3. Run the e2e suite on a schedule (nightly) rather than per-commit.

## Common pitfalls

- **Re-encoding the body in tests.** `json_encode(json_decode($body))`
  changes the byte sequence; the signature will not match. Sign and pass
  the same string both sides of the call.
- **Using the production secret in tests.** Even read-only operations
  log into Yoco's audit trail. Always use an `sk_test_*` key in CI.
- **Asserting on `paymentId` immediately after creating a checkout.**
  The field is `null` until the customer actually pays. Successful
  `$checkout->create()` does not mean a payment exists yet.
- **Asserting on `processingMode === 'live'` in tests.** It is `'test'`
  with `sk_test_*` keys — exactly what you want.

## Next steps

- [Webhook handling](webhook-handling.md) — the receiver pattern you
  are testing against.
- [Signature verification](signature-verification.md) — what the verifier
  is actually checking.
- [Error handling](error-handling.md) — the exception subclasses to assert
  on.
- [`tests/`](../../tests/) in this repo — the SDK's own test suite is a
  good reference for fixture-based testing.
