# Signature verification deep dive

This guide explains exactly what `SignatureVerifier::verify()` does, what
attacks it defends against, and how to debug it when something looks off.
For an end-to-end webhook walkthrough (registration, dedupe, dispatch),
read [Webhook handling](webhook-handling.md) first.

## Why

Webhooks are server-to-server requests from a public party (Yoco) to a
public URL (yours). Without a signature, anyone who guesses your URL can
POST a forged `payment.succeeded` and convince your app to mark an order
paid. The signature header proves Yoco is the sender and that nobody
modified the body in flight.

Yoco follows the
[Standard Webhooks](https://www.standardwebhooks.com/) specification — a
small, well-reviewed scheme using HMAC-SHA256 and three plain HTTP headers.
The SDK ships a compliant verifier; this guide unpacks what it does so you
can debug, audit, and integrate with confidence.

## The algorithm, end to end

Yoco signs a deterministic concatenation of three values and sends the
signature in a header. Verification recomputes the signature and compares
it in constant time.

### Inputs

- `secret` — the shared secret Yoco returned when you registered the
  subscription. Always starts with the literal prefix `whsec_`; the rest
  is base64-encoded random bytes.
- `id` — value of the `webhook-id` header. A unique, opaque identifier for
  this delivery (also surfaced as `WebhookEvent::$id`).
- `timestamp` — value of the `webhook-timestamp` header. Unix epoch
  seconds, as a string of ASCII digits.
- `body` — the **raw bytes** of the HTTP request body. Not a parsed object,
  not a re-encoded JSON string. The bytes Yoco sent, byte-for-byte.

### Steps

```text
1. decoded_secret = base64_decode(strip_prefix("whsec_", secret))
2. signed_payload = id + "." + timestamp + "." + body
3. signature      = base64( HMAC_SHA256(decoded_secret, signed_payload) )
4. verify         = for each "v1,X" in header webhook-signature:
                        if hash_equals(signature, X): accept
                    if no entry matched: reject
```

The `webhook-signature` header is **a space-separated list** of versioned
signatures. Today only `v1` is defined; the SDK ignores other prefixes
(`v0`, `v2`, custom schemes) so that mixed-version deliveries during a
rotation do not fail outright.

### Replay protection

Before verifying the signature, the SDK checks that the request is recent:

```text
delta = abs(now - timestamp)
if delta > toleranceSeconds: reject
```

The default tolerance is **180 seconds** (3 minutes); the hard maximum
defined by the SDK is **3600 seconds** (1 hour). A short window means that
even if an attacker captures a real webhook in transit, they only have
minutes to replay it before the timestamp falls out of tolerance.

You can tighten or widen the window per call:

```php
$event = $verifier->verify($rawBody, $headers, toleranceSeconds: 60);
```

…but the SDK refuses anything above 3600. If you find yourself wanting a
bigger window, the problem is almost always clock drift on your server —
fix it with NTP rather than weakening replay protection.

## What the SDK enforces

The verifier is `SignatureVerifier::verify()`. It enforces, in order:

| Check                                                    | Outcome on failure                         |
|----------------------------------------------------------|--------------------------------------------|
| `toleranceSeconds` is in `[0, 3600]`                     | `InvalidArgumentException`                 |
| Body is ≤ 1 MiB (`MAX_BODY_BYTES = 1_048_576`)            | `SignatureVerificationException`           |
| `webhook-id` header is non-empty                          | `SignatureVerificationException`           |
| `webhook-timestamp` header is non-empty                   | `SignatureVerificationException`           |
| `webhook-signature` header is non-empty                   | `SignatureVerificationException`           |
| `webhook-timestamp` parses as ASCII digits                | `SignatureVerificationException`           |
| `abs(now - timestamp) ≤ toleranceSeconds`                 | `SignatureVerificationException`           |
| Secret has `whsec_` prefix                                | `SignatureVerificationException`           |
| Secret base64-decodes to non-empty bytes                  | `SignatureVerificationException`           |
| Header contains at least one `v1,*` entry                 | `SignatureVerificationException`           |
| At least one `v1,*` entry matches the computed signature  | `SignatureVerificationException`           |
| Body parses as JSON with depth ≤ 64                       | `SignatureVerificationException`           |
| Decoded JSON has `id`, `type`, `createdDate`, `payload`   | `SignatureVerificationException`           |

If every check passes, `verify()` returns a
[`WebhookEvent`](../api/dtos.md) DTO.

### Why all those checks

- **Body size cap.** A 100 GB JSON body would not just fail to parse — it
  would take down the receiver before parsing began. 1 MiB is plenty for
  Yoco's payloads and is the same cap `HttpClient` enforces on outbound
  responses.
- **JSON depth cap.** Defends against pathological inputs designed to
  exhaust the parser stack (the historical "billion laughs" pattern).
- **Constant-time comparison.** `hash_equals()` runs in time proportional
  to the string length regardless of where the first mismatch occurs. The
  naïve `===` operator short-circuits, which leaks how many leading bytes
  matched and lets a remote attacker grind the signature one byte at a
  time. Never compare HMACs with `==` or `===`.
- **Required headers, fail-closed.** A request that does not even claim
  to be signed is rejected before any work is done.
- **Forward-compatible signature parsing.** The verifier knows it can
  ignore `v0` and `v2` entries and only fails when no `v1` entry is
  present at all.

## Header normalisation

PHP, Slim, Laravel, and Symfony all surface inbound headers in subtly
different shapes:

- `getallheaders()` — `['Webhook-Id' => 'evt_...', ...]`
- Slim — `['webhook-id' => ['evt_...']]` (always arrays)
- Laravel — `['webhook-id' => ['evt_...']]` from `$request->headers->all()`
- Symfony — same as Laravel

The verifier normalises both shapes:

- Header **names** are lower-cased before lookup, so `Webhook-Id` and
  `webhook-id` are equivalent.
- Header **values** that are arrays are reduced to their first element
  (since these headers are single-valued). An empty array is treated as
  a missing header.

You can pass whichever shape your framework gives you. The verifier signature
is:

```php
public function verify(
    string $rawBody,
    array $headers,
    int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
): WebhookEvent
```

…where `$headers` is `array<string, string|list<string>>`.

## Replay protection: why 180s?

Choosing the tolerance window is a trade-off between:

- **Tight (e.g. 30s).** Maximises replay protection. Strict enough that
  any clock drift between Yoco and your server can cause valid webhooks
  to be rejected. Acceptable only if you run NTP and have low-latency
  ingress.
- **Loose (e.g. 300s).** Tolerates more drift and delays. Bigger window for
  an attacker who captured a webhook to replay it.

180 seconds is the Standard Webhooks recommended default and is what the
SDK uses unless you override it. Most legitimate webhooks arrive within a
few seconds; the rest is buffer for retries, clock skew, and slow networks.

Hard ceiling: **3600 seconds.** Beyond that the SDK refuses outright — at
that point you have switched off replay protection in practice.

## What is NOT verified

`verify()` is **only** about authenticity and freshness of the message. It
does not validate domain semantics. In particular:

- **The event id, type, payment id, amount, and metadata are not
  cross-checked** against anything you stored when you created the
  checkout. That correlation is your application's job.
- **The shape of the `payload` array is not validated** beyond it being an
  array. Yoco can (and does) add new fields. The DTO surfaces it as
  `array<string, mixed>`; treat unknown keys with `??`.
- **There is no allowlist of `event.type`.** Future event types arrive
  via the same signed channel. Branch defensively and `default:` to a
  log line, never a crash.

If you need stricter shape validation, build a thin adapter that translates
`WebhookEvent::$payload` into a domain object. The SDK deliberately stops
at "this came from Yoco, recently, unmodified".

## Injecting the clock for tests

`SignatureVerifier` accepts an optional clock callable so you can drive it
deterministically in tests. Useful for:

- Regression-testing the tolerance window without `usleep()` in your suite.
- Replaying historical webhook payloads (e.g. captured in production logs)
  during incident analysis.
- Property-based tests around the boundary at `tolerance ± 1`.

```php
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

$fixedTimestamp = 1_700_000_000;

$verifier = new SignatureVerifier(
    secret: 'whsec_' . base64_encode(random_bytes(32)),
    clock:  static fn (): int => $fixedTimestamp,
);

// Any webhook signed with timestamp 1_700_000_000 (± tolerance) verifies.
```

## Debugging a failing signature

If `verify()` keeps throwing in production:

1. **Confirm you have the raw body.** Re-encoded JSON never matches. Log
   `bin2hex(substr($rawBody, 0, 64))` and compare it to what Yoco sent
   (you can see deliveries in the Yoco dashboard).
2. **Confirm your secret is the one Yoco issued for *this* subscription.**
   Subscriptions in test and live mode have different secrets. Mixing them
   produces `SignatureVerificationException` with the message *"No
   matching signature found"*.
3. **Confirm the secret has the `whsec_` prefix.** If you stripped it
   when storing, the verifier will throw *"Webhook secret must start with
   whsec_"*.
4. **Check your clock.** A server 10 minutes behind real time will fail
   every webhook. Run `timedatectl` (Linux) or check Cloudflare's
   `cf-ray` header arrival time. If you cannot fix the drift right now,
   pass `toleranceSeconds: 600` as a temporary workaround.
5. **Trace the headers.** Log all incoming headers (with secrets and
   bearer tokens redacted) and confirm the three Standard Webhooks
   headers are present and non-empty. A proxy stripping them out is a
   common failure mode.

## FAQ

### Can I run verification asynchronously in a queue worker?

Yes — as long as you pass the *raw body* and the original headers into
the worker. Do not store a parsed JSON object and re-encode it inside the
worker: the recomputed bytes will not match. Serialize `$rawBody` and
`$headers` directly.

A common pattern:

```php
// Receiver: persist what you need, ack fast.
DB::table('inbound_yoco_events')->insert([
    'received_at' => now(),
    'raw_body'    => $rawBody,
    'headers'     => json_encode($headers, JSON_THROW_ON_ERROR),
]);

ProcessYocoEvent::dispatch();
http_response_code(200);
```

```php
// Worker: verify against the bytes you stored.
$row = DB::table('inbound_yoco_events')->oldest()->first();
$verifier->verify($row->raw_body, json_decode($row->headers, true));
```

### What if I have multiple webhook subscriptions with different secrets?

Use one `SignatureVerifier` instance per secret and try each in turn:

```php
foreach ($secrets as $secret) {
    $verifier = new SignatureVerifier($secret);

    try {
        return $verifier->verify($rawBody, $headers);
    } catch (SignatureVerificationException) {
        continue;
    }
}

throw new SignatureVerificationException('No subscription matched');
```

Costs O(N) HMACs per request, but N is typically 1 or 2. Returning the
matched secret alongside the event is occasionally useful for audit.

### Does the SDK support `v0` or `v2` signatures?

Only `v1`. The verifier silently skips entries with other prefixes and
only fails if *no* `v1` entry is present. This makes the verifier
forward-compatible with a future Yoco rotation that publishes `v1` and
`v2` side by side — your receiver keeps verifying `v1` until you upgrade.

### Why is the secret prefixed with `whsec_`?

The prefix is part of the Standard Webhooks spec. It lets credential
scanners (e.g. GitHub secret scanning) detect leaked secrets in source
code and notify Yoco, which can then revoke them automatically. Do not
strip the prefix when storing the secret.

## Next steps

- [Webhook handling](webhook-handling.md) — the end-to-end flow this
  verifier slots into.
- [Testing](testing.md) — building deterministic signature vectors for
  your test suite.
- [`Webhook\SignatureVerifier` API reference](../api/signature-verifier.md) —
  full method-by-method docs.
- [Standard Webhooks specification](https://www.standardwebhooks.com/) —
  the upstream spec the SDK implements.
