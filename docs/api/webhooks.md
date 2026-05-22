# Sonnenglas\Yoco\Resources\Webhooks

> Create, list, and delete webhook subscriptions on your Yoco account.

```php
namespace Sonnenglas\Yoco\Resources;

class Webhooks extends BaseResource
```

Obtain this resource through `$client->webhooks()`. Yoco accepts at most
**5 webhook subscriptions per account** (per Yoco documentation).

## Methods

### `create($name, $url)`

> Register a new webhook subscription. The returned `secret` is shown **only on this response** — store it immediately.

**Signature:**

```php
public function create(string $name, string $url): \Sonnenglas\Yoco\Dto\WebhookSubscription
```

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$name` | `string` | yes | Human-readable label for the subscription (e.g. `'production'`, `'staging'`). Helps identify subscriptions in `list()` output. |
| `$url` | `string` | yes | Public HTTPS endpoint Yoco will POST events to. Must be reachable from the public internet. |

**Returns:** `Dto\WebhookSubscription` — `id`, `name`, `url`, `mode` (`'live'` or `'test'`), and `secret` (the `whsec_…` value used for signature verification).

**Throws:**

- `Sonnenglas\Yoco\Exceptions\AuthenticationException` — HTTP 401/403.
- `Sonnenglas\Yoco\Exceptions\ValidationException` — HTTP 400 (e.g. malformed URL, non-HTTPS).
- `Sonnenglas\Yoco\Exceptions\RateLimitException` — HTTP 429.
- `Sonnenglas\Yoco\Exceptions\ApiException` — any other 4xx/5xx, malformed
  response, or PSR-18 transport error. Also thrown if the response is
  missing one of the required fields `id`, `name`, `url`, `mode`.

**Critical — secret is returned only once.** Yoco does not return the
`secret` from `list()` or any later call. If you lose it, you must delete
and re-create the subscription. Persist it to your secrets store immediately:

```php
$subscription = $client->webhooks()->create(
    name: 'production',
    url:  'https://shop.example/hooks/yoco',
);

// 1. Store the secret in a secrets manager BEFORE doing anything else.
$secrets->put('yoco.webhook.secret', $subscription->secret);

// 2. Then store the subscription id so you can delete it later.
$db->store('yoco_webhook_subscription_id', $subscription->id);
```

`WebhookSubscription::__debugInfo()` redacts the secret as
`'***redacted***'`, so `var_dump($subscription)` after persistence is safe.

### `list()`

> Return all active webhook subscriptions on the account. Secrets are **not** returned by this endpoint.

**Signature:**

```php
public function list(): array
```

**Returns:** `list<Dto\WebhookSubscription>` — each entry has `id`, `name`, `url`, `mode`; `secret` is always `null`.

**Throws:**

- `Sonnenglas\Yoco\Exceptions\ApiException` — when the response body is
  missing the `subscriptions` key, or the value is not an array, or an
  individual entry is malformed. The SDK fails **loudly** here rather than
  silently returning an empty list.
- `Sonnenglas\Yoco\Exceptions\AuthenticationException` — HTTP 401/403.
- `Sonnenglas\Yoco\Exceptions\RateLimitException` — HTTP 429.
- `Sonnenglas\Yoco\Exceptions\ApiException` — any other 4xx/5xx.

**Example:**

```php
foreach ($client->webhooks()->list() as $sub) {
    printf("%s  %s  (%s)  → %s\n", $sub->id, $sub->name, $sub->mode, $sub->url);
}
// wh_abc  production  (live)  → https://shop.example/hooks/yoco
// wh_def  staging     (test)  → https://staging.example/hooks/yoco
```

### `delete($id)`

> Permanently delete a webhook subscription.

**Signature:**

```php
public function delete(string $id): void
```

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$id` | `string` | yes | The subscription id (e.g. `wh_…`). URL-encoded by the SDK before being placed in the path. |

**Returns:** `void`.

**Throws:**

- `Sonnenglas\Yoco\Exceptions\AuthenticationException` — HTTP 401/403.
- `Sonnenglas\Yoco\Exceptions\ApiException` — any other 4xx/5xx
  (including 404 if the subscription does not exist).
- `Sonnenglas\Yoco\Exceptions\RateLimitException` — HTTP 429.

**Example:**

```php
$client->webhooks()->delete('wh_abc');
```

---

## What's not here: `retrieve($id)`

The Yoco Online Checkout API does **not** expose a `GET /webhooks/{id}`
endpoint. To inspect a specific subscription, call `list()` and filter
client-side:

```php
$id = 'wh_abc';
$match = null;

foreach ($client->webhooks()->list() as $sub) {
    if ($sub->id === $id) {
        $match = $sub;
        break;
    }
}

if ($match === null) {
    // not found
}
```

## Rotating a webhook secret

Yoco's API has no in-place rotation endpoint. The SDK reflects that — there
is no `rotate()` method. Manual rotation flow:

```php
// 1. Create the new subscription pointing to the same URL.
//    Yoco accepts multiple subscriptions for the same URL.
$new = $client->webhooks()->create(
    name: 'production-rotated-2026-05-22',
    url:  'https://shop.example/hooks/yoco',
);

// 2. Persist the NEW secret. Do NOT overwrite the old one yet —
//    the verifier needs both during the grace period (your verifier
//    can try them in turn and accept the first that matches).
$secrets->put('yoco.webhook.secret.new', $new->secret);

// 3. Deploy and confirm new events verify against the new secret.

// 4. Delete the old subscription once you no longer see events
//    arriving against the old secret (give Yoco ≈10 minutes to settle).
$client->webhooks()->delete($oldSubscriptionId);

// 5. Promote the new secret to primary and remove the old one.
$secrets->promote('yoco.webhook.secret.new', 'yoco.webhook.secret');
```

During the overlap, both subscriptions are active and Yoco will deliver the
**same event** to **both** URLs. Make sure your event processing is
idempotent on `WebhookEvent::$id` so duplicate deliveries are harmless.

See the [signature verification guide](../guides/signature-verification.md)
for a worked example of a verifier that tries multiple secrets.

## See also

- [`Dto\WebhookSubscription`](dtos.md#webhooksubscription) — the returned DTO.
- [`Webhook\SignatureVerifier`](signature-verifier.md) — verify inbound
  events using the secret returned by `create()`.
- [Webhook handling guide](../guides/webhook-handling.md) — full lifecycle.
