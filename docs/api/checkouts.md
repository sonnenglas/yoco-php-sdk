# Sonnenglas\Yoco\Resources\Checkouts

> Create hosted Yoco Online Checkout sessions and issue full or partial refunds.

```php
namespace Sonnenglas\Yoco\Resources;

class Checkouts extends BaseResource
```

Obtain this resource through `$client->checkouts()`. Do not instantiate it
directly — its constructor takes the internal `HttpClient`.

## Methods

### `create($request, $idempotencyKey = null)`

> Create a Yoco hosted checkout session. The returned `redirectUrl` is what you redirect the customer's browser to.

**Signature:**

```php
public function create(
    \Sonnenglas\Yoco\Dto\CreateCheckoutRequest $request,
    ?string $idempotencyKey = null,
): \Sonnenglas\Yoco\Dto\CheckoutResponse
```

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$request` | `Dto\CreateCheckoutRequest` | yes | Validated request object. See the field table below. |
| `$idempotencyKey` | `?string` | no | Optional client-supplied `Idempotency-Key`. When `null`, the SDK auto-generates a fresh UUID v4. Re-using the same key for retries makes the request safe to repeat — Yoco returns the original response and does **not** create a duplicate checkout. |

#### `CreateCheckoutRequest` fields

| Field | Type | Required | Constraint | Description |
|-------|------|----------|------------|-------------|
| `amount` | `int` | yes | `>= 200` (R2.00) | Charge amount in **cents**. Yoco's minimum charge is R2.00. |
| `currency` | `string` | yes | must equal `'ZAR'` | The only supported currency. |
| `successUrl` | `string` | yes | non-empty | Redirected to on successful payment. |
| `cancelUrl` | `string` | yes | non-empty | Redirected to when the customer cancels. |
| `failureUrl` | `?string` | no | — | Redirected to on payment failure. Falls back to `cancelUrl` if omitted. |
| `metadata` | `array<string, scalar>` | no | — | Arbitrary key/value pairs returned on the checkout and on every webhook event. Use for your own order linkage. |
| `lineItems` | `list<LineItem>` | no | — | Itemised line items for receipt display. |
| `totalDiscount` | `?int` | no | — | Total discount in cents (informational). |
| `totalTaxAmount` | `?int` | no | — | Total tax in cents (informational). |
| `subtotalAmount` | `?int` | no | — | Subtotal in cents (informational). |
| `externalId` | `?string` | no | — | Your external reference (e.g. order number). Returned on the checkout response and accessible via webhooks. |

#### `CheckoutResponse` fields

| Field | Type | Always set? | Description |
|-------|------|-------------|-------------|
| `id` | `string` | yes | The checkout id, e.g. `ch_9LVKD8GnAj7f39DFbn4F16bE`. |
| `redirectUrl` | `string` | yes | Send the customer's browser here. |
| `status` | `string` | yes | Lifecycle state (`'created'`, etc.). |
| `amount` | `int` | yes | Echo of the amount in cents. |
| `currency` | `string` | yes | Echo of the currency (`'ZAR'`). |
| `paymentId` | `?string` | only after payment | `p_…` payment id. Links to the broader Yoco payments resource. |
| `processingMode` | `?string` | usually | `'live'` or `'test'` depending on which secret key created the checkout. |
| `merchantId` | `?string` | usually | The Yoco merchant id. |
| `clientReferenceId` | `?string` | optional | Echo of `externalId` from the request when present. |

**Returns:** `Dto\CheckoutResponse`

**Throws:**

- `Sonnenglas\Yoco\Exceptions\ValidationException` — HTTP 400 from Yoco
  (invalid body or parameters).
- `Sonnenglas\Yoco\Exceptions\AuthenticationException` — HTTP 401 or 403
  (missing or invalid API key — Yoco uses 403 for invalid keys).
- `Sonnenglas\Yoco\Exceptions\IdempotencyConflictException` — HTTP 409
  (another request with the same `Idempotency-Key` is still being processed;
  retry after a short delay).
- `Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException` — HTTP 422
  (you re-used the same `Idempotency-Key` with a **different** request body
  — generate a new key or re-send the original payload).
- `Sonnenglas\Yoco\Exceptions\RateLimitException` — HTTP 429
  (defensive; check `$retryAfter`).
- `Sonnenglas\Yoco\Exceptions\ApiException` — any other 4xx/5xx, malformed
  response, or PSR-18 transport error.
- `Sonnenglas\Yoco\Exceptions\ApiException` — the response could not be
  decoded into a `CheckoutResponse` (e.g. required field missing). The
  underlying `$data` is preserved on `$responseBody`.

**Example — minimal create:**

```php
use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

$client = new Client(secretKey: getenv('YOCO_SECRET_KEY'));

$response = $client->checkouts()->create(new CreateCheckoutRequest(
    amount: 10000,                              // 100.00 ZAR (cents)
    currency: 'ZAR',
    successUrl: 'https://shop.example/order/123/thanks',
    cancelUrl:  'https://shop.example/order/123/cancelled',
));

header('Location: '.$response->redirectUrl);
exit;
```

**Example — full create with line items and metadata:**

```php
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Dto\LineItem;
use Sonnenglas\Yoco\Dto\PricingDetails;

$request = new CreateCheckoutRequest(
    amount: 15000,                              // 150.00 ZAR
    currency: 'ZAR',
    successUrl: 'https://shop.example/thanks',
    cancelUrl:  'https://shop.example/cancel',
    failureUrl: 'https://shop.example/failure',
    metadata: [
        'orderNumber' => 'ORD-100',
        'customerId'  => 'cust-7',
    ],
    lineItems: [
        new LineItem(
            displayName: 'Sonnenglas Classic 250ml',
            quantity:    1,
            pricingDetails: new PricingDetails(price: 15000),
            description: 'Solar lamp in a jar',
        ),
    ],
    externalId: 'ORD-100',
);

$response = $client->checkouts()->create($request);
```

### `refund($checkoutId, $amount = null, $idempotencyKey = null)`

> Issue a full or partial refund against a previously successful checkout.

**Signature:**

```php
public function refund(
    string $checkoutId,
    ?int $amount = null,
    ?string $idempotencyKey = null,
): \Sonnenglas\Yoco\Dto\RefundResponse
```

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$checkoutId` | `string` | yes | The checkout id (e.g. `ch_9LVKD8GnAj7f39DFbn4F16bE`). Must be non-empty; URL-encoded by the SDK. |
| `$amount` | `?int` | no | Refund amount in **cents**. Pass `null` (the default) for a full refund of the original amount. Must be at least `1` when provided. |
| `$idempotencyKey` | `?string` | no | Optional client-supplied `Idempotency-Key`. Auto-generated UUID v4 if `null`. Re-using the same key safely retries the same refund. |

**Returns:** `Dto\RefundResponse` — `id` (`rf_…`), `status`, `amount`, `currency`, and optionally `checkoutId` and `paymentId`.

**Throws:**

- `\InvalidArgumentException` — `$checkoutId` is empty, or `$amount` is
  provided but less than `1`. Thrown before any HTTP call is made.
- `Sonnenglas\Yoco\Exceptions\ValidationException` — HTTP 400 (Yoco rejected
  the refund — for example, the checkout was never paid).
- `Sonnenglas\Yoco\Exceptions\AuthenticationException` — HTTP 401 or 403.
- `Sonnenglas\Yoco\Exceptions\IdempotencyConflictException` — HTTP 409.
- `Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException` — HTTP 422.
- `Sonnenglas\Yoco\Exceptions\RateLimitException` — HTTP 429.
- `Sonnenglas\Yoco\Exceptions\ApiException` — any other 4xx/5xx, malformed
  response, or PSR-18 transport error.

**Example — full refund:**

```php
$refund = $client->checkouts()->refund(
    checkoutId: 'ch_9LVKD8GnAj7f39DFbn4F16bE',
);

echo $refund->id;       // rf_…
echo $refund->status;   // 'created' | 'succeeded' | …
echo $refund->amount;   // original checkout amount in cents
```

**Example — partial refund:**

```php
$refund = $client->checkouts()->refund(
    checkoutId: 'ch_9LVKD8GnAj7f39DFbn4F16bE',
    amount:     2500,                         // 25.00 ZAR
);
```

**Note:** Test-mode secret keys (`sk_test_*`) cannot issue refunds — Yoco's
test API rejects refund requests. To exercise the full refund flow you need
a live key or Yoco's sandbox account, depending on which environment the
checkout was created in.

---

## Idempotency-Key

`Checkouts::create()` and `Checkouts::refund()` both accept an optional
`$idempotencyKey`. The SDK behaviour is:

- **Caller did not pass one** → the SDK generates a fresh RFC 4122 v4 UUID
  and sends it as `Idempotency-Key`. Random bytes come from
  `random_bytes(16)`; failure to source them throws `ApiException`.
- **Caller passed one** → it is sent verbatim. Yoco identifies the request
  by `(secret_key, idempotency_key)`, so two callers with different keys
  cannot collide.

**Why pass your own key?**

To make retries safe across process restarts. Use a deterministic key that
your application can regenerate — e.g. `"order-{id}-create-{attempt}"`. On
retry Yoco returns the **original** response (HTTP 200 with the same
`ch_…` id) rather than creating a duplicate checkout.

**Conflict semantics (Yoco's contract):**

| Scenario | HTTP | Exception |
|----------|------|-----------|
| Same key, same body, after original completed | 200 | (none — original response is returned) |
| Same key, same body, original still in flight | 409 | `IdempotencyConflictException` — retry after a short delay |
| Same key, **different** body | 422 | `IdempotencyMismatchException` — choose a new key or re-send the original body |

The 422 case is **not** a request-validation error. Generic validation
errors (`amount` missing, `currency` not `ZAR`, etc.) return 400 and surface
as `ValidationException`.

## See also

- [`Dto\CreateCheckoutRequest`](dtos.md#createcheckoutrequest)
- [`Dto\CheckoutResponse`](dtos.md#checkoutresponse)
- [`Dto\RefundResponse`](dtos.md#refundresponse)
- [`Dto\LineItem`](dtos.md#lineitem) and [`Dto\PricingDetails`](dtos.md#pricingdetails)
- [Exceptions](exceptions.md) — HTTP status to exception mapping.
- [Error handling guide](../guides/error-handling.md) — retry patterns for
  idempotency conflicts and rate-limit responses.
