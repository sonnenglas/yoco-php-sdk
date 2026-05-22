# Sonnenglas\Yoco\Webhook\SignatureVerifier

> Verify inbound webhook requests against the Standard Webhooks v1 HMAC-SHA256 scheme, returning a parsed `WebhookEvent`.

```php
namespace Sonnenglas\Yoco\Webhook;

final class SignatureVerifier
```

The verifier is **stateless** apart from the secret and an optional clock
callable. It does not perform HTTP requests of its own — it operates purely
on the raw body and headers your application captured from the incoming
webhook request.

## Constants

| Constant | Type | Value | Description |
|----------|------|-------|-------------|
| `SignatureVerifier::DEFAULT_TOLERANCE_SECONDS` | `int` | `180` | Default replay-protection window (±3 minutes). |
| `SignatureVerifier::MAX_TOLERANCE_SECONDS` | `int` | `3600` | Hard upper bound for `$toleranceSeconds`. Larger values throw `InvalidArgumentException`. |
| `SignatureVerifier::MAX_BODY_BYTES` | `int` | `1_048_576` | Maximum accepted raw body size (1 MiB). Anything larger is rejected without being parsed. |

## Constructor

### `__construct($secret, $clock = null)`

> Build a verifier bound to a single webhook secret.

**Signature:**

```php
public function __construct(
    string $secret,
    ?callable $clock = null,
)
```

**Parameters:**

| Name | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `$secret` | `string` | yes | — | The `whsec_…` value returned from `Webhooks::create()`. Format is `whsec_<base64-encoded-bytes>`. |
| `$clock` | `?callable(): int` | no | `null` | Returns the current Unix timestamp. When `null`, the verifier uses `time()`. Useful for tests and for verifying historical events (where "current time" is the original delivery time). |

**Example — production:**

```php
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

$verifier = new SignatureVerifier(getenv('YOCO_WEBHOOK_SECRET'));
```

**Example — test with frozen clock:**

```php
$verifier = new SignatureVerifier(
    secret: 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
    clock:  fn (): int => 1614265330,
);
```

## Methods

### `verify($rawBody, $headers, $toleranceSeconds = 180)`

> Verify a webhook request and return the parsed event. Throws if anything is wrong.

**Signature:**

```php
public function verify(
    string $rawBody,
    array $headers,
    int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
): \Sonnenglas\Yoco\Dto\WebhookEvent
```

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$rawBody` | `string` | yes | The **raw** HTTP request body bytes, exactly as received. Do not pre-parse, re-serialise, or trim whitespace — any modification breaks the signature. |
| `$headers` | `array<string, string\|list<string>>` | yes | Request headers. Names are matched case-insensitively. PSR-7 array-of-strings values are flattened to the first entry. Must include `webhook-id`, `webhook-timestamp`, `webhook-signature`. |
| `$toleranceSeconds` | `int` | no (default `180`) | Replay-protection window. Must be between `0` and `MAX_TOLERANCE_SECONDS` (3600). |

**Returns:** `Dto\WebhookEvent` — parsed event with `id`, `type`, `createdDate`, and raw `payload` array.

**Throws:**

- `\InvalidArgumentException` — `$toleranceSeconds` is negative or greater than `MAX_TOLERANCE_SECONDS`.
- `Sonnenglas\Yoco\Exceptions\SignatureVerificationException` for **any** of the following:
  - Body exceeds `MAX_BODY_BYTES` (1 MiB).
  - `webhook-id`, `webhook-timestamp`, or `webhook-signature` header missing or empty.
  - `webhook-timestamp` is not a decimal integer.
  - `webhook-timestamp` is outside the `±toleranceSeconds` window around now (or around `$clock()` if injected).
  - Secret does not start with `whsec_`.
  - Secret has invalid base64 encoding after the `whsec_` prefix.
  - `webhook-signature` header contains `v1,` entries but none match the computed HMAC.
  - `webhook-signature` header contains only unknown schemes (e.g. `v0,`, `v2,`) with no `v1,` entry. The error message distinguishes this case from a mismatched signature.
  - Body is not valid JSON.
  - Decoded body is missing one of the required event fields: `id` (string), `type` (string), `createdDate` (string), `payload` (array).

**Example — minimal:**

```php
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

$verifier = new SignatureVerifier(getenv('YOCO_WEBHOOK_SECRET'));

try {
    $event = $verifier->verify(
        rawBody: file_get_contents('php://input'),
        headers: getallheaders(),
    );
} catch (SignatureVerificationException $e) {
    http_response_code(401);
    exit;
}

echo $event->type;                              // 'payment.succeeded'
echo $event->payload['metadata']['orderNumber'] ?? '';
```

**Example — custom tolerance and frozen clock (test):**

```php
$verifier = new SignatureVerifier(
    secret: $secret,
    clock:  fn (): int => 1614265330,
);

$event = $verifier->verify(
    rawBody: $body,
    headers: [
        'webhook-id'        => 'msg_test',
        'webhook-timestamp' => '1614265330',
        'webhook-signature' => 'v1,'.$expectedSig,
    ],
    toleranceSeconds: 60,
);
```

**Example — Slim 4 / plain PSR-7 receiver:**

```php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

return function (
    ServerRequestInterface $request,
    ResponseInterface $response,
) use ($verifier): ResponseInterface {
    try {
        $event = $verifier->verify(
            rawBody: (string) $request->getBody(),
            headers: $request->getHeaders(),
        );
    } catch (SignatureVerificationException) {
        return $response->withStatus(401);
    }

    // Idempotent handling — guard against duplicate deliveries.
    dispatch_to_queue('yoco.event', $event->id, $event->type, $event->payload);

    return $response->withStatus(204);
};
```

---

## Algorithm

The SDK implements the [Standard Webhooks v1](https://www.standardwebhooks.com/)
scheme:

1. Strip the `whsec_` prefix from the configured secret.
2. Base64-decode the remainder to obtain the raw HMAC key bytes.
3. Build the signed payload string:

   ```
   signed_payload = "{webhook-id}.{webhook-timestamp}.{raw_body}"
   ```

4. Compute:

   ```
   expected = base64( hmac_sha256(decoded_secret, signed_payload) )
   ```

5. Parse the `webhook-signature` header as a **space-separated** list of
   `<version>,<signature>` tokens. Yoco may emit more than one signature
   during a secret rotation — the verifier accepts the request if **any**
   `v1,<sig>` token matches the computed expectation. Unknown prefixes
   (`v0,`, `v2,`, custom schemes) are silently skipped — but if every token
   uses an unsupported scheme, verification fails with a clear message.
6. Comparison uses `hash_equals` for constant-time equality (timing-attack
   safe).

If verification succeeds, the body is JSON-decoded with a maximum nesting
depth of 64 and a maximum size of 1 MiB. The result must be an object with
string `id`, string `type`, string `createdDate`, and array `payload` — any
deviation throws `SignatureVerificationException`.

## Notes and gotchas

- **Capture raw body before any middleware touches it.** Frameworks that
  auto-decode JSON into `$request->all()` discard the original bytes; the
  signature is computed over those bytes. In Laravel use
  `$request->getContent()`; in Symfony use `$request->getContent()`; in
  plain PHP use `file_get_contents('php://input')`.
- **Clock skew is real.** The default `±180s` window is generous for most
  setups. If your application server's clock drifts (containers, VMs
  without NTP), webhooks will start to fail at exactly 180 s of drift —
  fix the clock rather than raise the tolerance.
- **Header naming is case-insensitive.** Both
  `getallheaders()` (mixed case) and PSR-7's lowercased server headers
  work without translation.
- **Multiple signatures = secret rotation.** Yoco may send both old and new
  signatures during a rotation window; the verifier accepts the first one
  that matches.

## See also

- [`Dto\WebhookEvent`](dtos.md#webhookevent) — the parsed return value.
- [`Exceptions\SignatureVerificationException`](exceptions.md#signatureverificationexception)
- [`Resources\Webhooks`](webhooks.md) — manage the subscriptions that
  generate the secrets verified here.
- [Webhook handling guide](../guides/webhook-handling.md) and
  [signature verification guide](../guides/signature-verification.md) — for
  end-to-end task examples.
- [Standard Webhooks specification](https://www.standardwebhooks.com/) —
  reference for the scheme implemented by this class.
