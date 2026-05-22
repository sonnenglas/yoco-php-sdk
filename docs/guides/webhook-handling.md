# Webhook handling

A complete, end-to-end walkthrough of receiving Yoco webhooks: from
registering the subscription, through verifying the signature, to dispatching
the work into a queue so your endpoint stays fast.

## Why

A successful Yoco checkout has two halves:

1. **The browser redirect** — the customer hits `successUrl`. This is a
   convenience for the user; it is not authoritative. The customer could
   close the tab, the redirect could fail, or someone could craft a fake
   request to your `successUrl`.
2. **The webhook** — Yoco POSTs a signed event to your server when the
   payment outcome is final. This **is** authoritative.

**Always treat the webhook as the source of truth.** Mark orders paid in
response to `payment.succeeded`, not in response to the customer arriving
back at `successUrl`. Push (webhooks) beats polling (`GET /checkouts/{id}`)
on latency, server cost, and rate-limit pressure.

## How

The full lifecycle is seven steps. Each is independent enough that you can
implement them in order and ship after step 6 — step 7 (key rotation) only
matters once you are live.

### Step 1. Register a webhook subscription

You register one subscription per environment (test, live). Yoco returns the
**secret only once**, when the subscription is created. If you lose it you
must delete the subscription and create a new one.

```php
use Sonnenglas\Yoco\Client;

$client = new Client(secretKey: getenv('YOCO_SECRET_KEY'));

$subscription = $client->webhooks()->create(
    name: 'production',
    url:  'https://example.com/webhooks/yoco',
);

echo "Subscription id: {$subscription->id}\n";
echo "Mode:            {$subscription->mode}\n"; // 'live' or 'test'
echo "Secret:          {$subscription->secret}\n";
// ⚠️ Persist $subscription->secret IMMEDIATELY. It is returned only once.
```

Store the secret in a secret manager (Hashicorp Vault, AWS Secrets Manager,
GCP Secret Manager, Doppler, …) or — at minimum — an `.env` value that is
not in version control. Persist `$subscription->id` next to it so you can
delete or list the subscription later.

**Make the register command idempotent.** A naive script that calls
`webhooks()->create()` every deploy will accumulate duplicate subscriptions
and have you handling each event N times. Recommended pattern:

```php
// Pseudocode. See examples/04-register-webhook.php for a runnable version.
$existing = $client->webhooks()->list();

foreach ($existing as $sub) {
    if ($sub->url === $url && $sub->name === $name) {
        echo "Already registered: {$sub->id}\n";
        return;
    }
}

$new = $client->webhooks()->create(name: $name, url: $url);
storeSecretSomewhereSafe($new->secret);
```

In Laravel this typically becomes an artisan command; in Symfony, a
make:command class; in plain PHP, a deploy hook script.

### Step 2. Receive the webhook

Your endpoint is a public POST URL. Yoco sends:

- A JSON body (e.g. `{"id":"evt_...","type":"payment.succeeded",...}`).
- Three Standard Webhooks headers: `webhook-id`, `webhook-timestamp`,
  `webhook-signature`.

**Read the raw body before anything parses it.** Frameworks that auto-decode
JSON into a structured request object will re-serialize on echo, and the
re-serialized bytes will not match the bytes Yoco signed.

#### Plain PHP

```php
$rawBody = file_get_contents('php://input');
$headers = getallheaders();
```

#### Slim

```php
$rawBody = (string) $request->getBody();
$headers = $request->getHeaders();
```

#### Laravel

```php
$rawBody = $request->getContent();              // raw, untouched
$headers = $request->headers->all();
```

#### Symfony

```php
$rawBody = $request->getContent();              // raw, untouched
$headers = $request->headers->all();
```

### Step 3. Verify the signature

The verifier returns a typed `WebhookEvent` DTO on success and throws
`SignatureVerificationException` on any tampering, expiry, or malformed
input. **Verify before you look at the body.**

```php
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

$verifier = new SignatureVerifier(getenv('YOCO_WEBHOOK_SECRET'));

try {
    $event = $verifier->verify($rawBody, $headers);
} catch (SignatureVerificationException $e) {
    http_response_code(401);
    exit;
}
```

For the full algorithm and replay-protection model, see
[signature verification](signature-verification.md).

### Step 4. Deduplicate

Yoco may retry an event — for example if your endpoint returned a 5xx, or if
your initial 200 arrived after Yoco's timeout. **Treat every webhook as
potentially duplicate** and reject re-deliveries.

The cheapest dedupe key is the `webhook-id` header (also available as
`$event->id`). Store it the first time you see it and reject any repeat.

```php
// Pseudocode — adapt to your DB / cache.
if ($cache->has("yoco:event:{$event->id}")) {
    http_response_code(200); // already processed, ack as success
    return;
}

$cache->put("yoco:event:{$event->id}", 1, ttl: 86400 * 7);
```

A persistent unique constraint (e.g. a `processed_yoco_events` table with
`event_id` as primary key) gives stronger guarantees than a cache, since
the cache might evict the key before Yoco stops retrying.

Pair the cache check with a database-level unique index for defence in
depth — under load, two workers can hit the cache check at the same time
and both proceed; the unique index will catch the second one.

### Step 5. Dispatch async

Your endpoint should return `200` fast — well under 5 seconds. Do not run
your domain logic inside the HTTP request:

- Network calls (sending receipts, calling other internal APIs) are slow
  and fail-prone.
- Database transactions can deadlock.
- Yoco's delivery has a timeout. If you exceed it, Yoco assumes failure
  and retries — and now you have a duplicate to fight.

Push the work onto a queue:

```php
// Laravel
dispatch(new ProcessYocoEvent($event));

// Symfony Messenger
$messageBus->dispatch(new ProcessYocoEvent($event));

// Plain PHP — push the event id onto a Redis list, beanstalkd tube, etc.
$redis->lpush('yoco:events', json_encode([
    'event_id' => $event->id,
    'type'     => $event->type,
    'payload'  => $event->payload,
]));
```

Then immediately respond:

```php
http_response_code(200);
```

The queue worker does the real work — order updates, accounting hooks,
customer notifications, the lot.

### Step 6. Process the event

Inside the queue handler, branch on `$event->type`. Today Yoco emits:

| Event type           | Meaning                              |
|----------------------|--------------------------------------|
| `payment.succeeded`  | The customer's card was charged.     |
| `payment.failed`     | The charge attempt did not succeed.  |

Match the event back to your local order via metadata you set when you
created the checkout. **`metadata` is a Yoco-defined field nested inside
`payload`**; everything inside it is your application's convention.

```php
$orderNumber = $event->payload['metadata']['orderNumber'] ?? null;
$checkoutId  = $event->payload['metadata']['checkoutId']  ?? null;

if ($orderNumber === null) {
    // Log and reject — your metadata contract is violated.
    return;
}

$order = Order::firstWhere('number', $orderNumber);

switch ($event->type) {
    case 'payment.succeeded':
        $order->markPaid(
            paymentId: $event->payload['id'] ?? null,
            amount:    $event->payload['amount'] ?? null,
        );
        break;

    case 'payment.failed':
        $order->markPaymentFailed(reason: $event->payload['failureReason'] ?? null);
        break;

    default:
        // Forward-compatibility: log unknown types but do not crash.
        $logger->info('Unrecognised Yoco event type', ['type' => $event->type]);
}
```

**Why `metadata.orderNumber`, not `event.id` or `event.payload.id`?**

- `event.id` is Yoco's event id — useful for dedupe, not for matching to
  your order.
- `event.payload.id` is the Yoco payment id — useful for refunds, but you
  may not have stored it yet (you stored the checkout id earlier).
- `event.payload.metadata` is the bag of free-form key/values you set on
  `CreateCheckoutRequest`. Yoco echoes it back unchanged. This is the
  canonical way to bridge from Yoco's id space to yours.

The `checkoutId` key inside metadata is **also your convention** — Yoco does
not put it there automatically. Set it when you create the checkout:

```php
$request = new CreateCheckoutRequest(
    // ...
    metadata: [
        'orderNumber' => $order->number,
        'checkoutId'  => /* you do not know this yet — see note below */ null,
    ],
);

$checkout = $client->checkouts()->create($request);
```

If you want both ways to look up the order — by your number and by the Yoco
id — store the Yoco checkout id on your order after creating it, and look
it up server-side when the webhook arrives. Alternatively, recreate the
checkout with the id baked into metadata via a follow-up update (Yoco does
not support an update endpoint at the moment, so the cleaner pattern is
`orderNumber → DB → checkoutId`).

### Step 7. Rotation

Once you are live, you will eventually need to rotate the webhook secret —
quarterly is a reasonable cadence; immediately if you suspect the secret
leaked. Yoco does not have an in-place rotation API, so the procedure is:

1. **Create a new subscription** pointing at the same URL but with a
   different `name` (e.g. `production-v2`).
2. **Persist the new secret.** Your verifier code must now know about
   *both* secrets — the old one and the new one — so that in-flight
   deliveries against the old secret still verify during the cutover.
3. **Wait** until you no longer see deliveries against the old secret —
   typically 24 hours of grace covers Yoco's retry tail.
4. **Delete the old subscription:**
   ```php
   $client->webhooks()->delete($oldSubscriptionId);
   ```
5. **Drop the old secret** from your secret store.

If you do not have application-level support for two secrets, the
quick-and-dirty fallback is to instantiate two `SignatureVerifier`s and
try each; whichever throws first is the wrong one. Returning to a single
verifier once the old subscription is deleted keeps the receiver clean.

The SDK itself **supports multi-version signature parsing** within a
single delivery — Yoco may send `webhook-signature: v1,abc... v1,def...`
during partial rotations or as a forward-compatibility hedge. The
verifier walks every `v1,*` entry and accepts the request if any one of
them matches. You do not have to do anything for that case.

## Common pitfalls

- **Parsing the body before verifying it.** `json_decode($rawBody)` then
  `json_encode($decoded)` does not round-trip — key ordering and number
  formatting will drift, and the recomputed HMAC will not match. Always
  verify against the raw bytes that came off the wire.
- **Trimming or normalising the body.** Anything that mutates the bytes
  (newline conversion, UTF-8 BOM stripping, trailing whitespace removal,
  `mb_convert_encoding`) destroys the signature. The verifier wants exactly
  what Yoco sent.
- **Catching `SignatureVerificationException` too broadly.** That exception
  is the only line of defence against forged webhooks. Do not swallow it
  silently — log it loudly, return `401`, and alert on a spike.
- **Configuring a tolerance window > 3600 seconds.** The verifier hard-caps
  at one hour. Pushing higher than that opens you up to replay attacks
  against captured webhook payloads.
- **Doing real work in the HTTP request.** See step 5 — anything more than
  "verify, dedupe, enqueue" belongs in a queue worker.
- **Sharing one subscription between live and test.** Yoco issues separate
  secrets per subscription, and the `$subscription->mode` field tells you
  which environment a subscription belongs to. Mixing them will mean test
  events trying to update real orders.

## Next steps

- [Signature verification](signature-verification.md) — deep dive on the
  HMAC scheme, replay protection, and the security model.
- [Testing](testing.md) — drive the verifier with a fixed clock and
  pre-computed signatures.
- [Error handling](error-handling.md) — what to do when the webhook
  endpoint itself throws.
- [Laravel](laravel-integration.md) / [Symfony](symfony-integration.md) /
  [Plain PHP](plain-php.md) — ready-to-adapt receiver code.
- [`examples/03-handle-webhook.php`](../../examples/03-handle-webhook.php) —
  the minimal receiver, runnable from `php -S`.
- [`examples/04-register-webhook.php`](../../examples/04-register-webhook.php) —
  idempotent subscription registration.
