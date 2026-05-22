# Data Transfer Objects

> Every request and response in the SDK is modelled as a `final readonly` PHP class under `Sonnenglas\Yoco\Dto\`.

Conventions used throughout this page:

- **Request DTOs** validate their fields in the constructor and expose a
  `toArray(): array` method that produces the exact body sent to the Yoco
  API. Optional fields are omitted from the array when `null` / empty —
  the SDK never sends `null` placeholders.
- **Response DTOs** are constructed through a static `fromArray(array $data): self`
  factory. The factory validates required fields and throws
  `Sonnenglas\Yoco\Exceptions\ApiException` (with the original payload
  preserved on `$responseBody`) if the response is malformed.

---

## Request DTOs

### `CreateCheckoutRequest`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class CreateCheckoutRequest
```

#### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `CreateCheckoutRequest::MIN_AMOUNT_CENTS` | `200` | Yoco's minimum charge (R2.00). |
| `CreateCheckoutRequest::SUPPORTED_CURRENCY` | `'ZAR'` | The only currency Yoco Online Checkout accepts. |

#### Constructor

```php
public function __construct(
    public int $amount,
    public string $currency,
    public string $successUrl,
    public string $cancelUrl,
    public ?string $failureUrl = null,
    public array $metadata = [],          // array<string, scalar>
    public array $lineItems = [],         // list<LineItem>
    public ?int $totalDiscount = null,
    public ?int $totalTaxAmount = null,
    public ?int $subtotalAmount = null,
    public ?string $externalId = null,
)
```

**Validation (raises `\InvalidArgumentException`):**

- `amount < MIN_AMOUNT_CENTS` → `'amount must be at least 200 cents (R2.00)'`.
- `currency !== 'ZAR'` → `'Yoco only supports ZAR currency'`.
- `successUrl === ''` → `'successUrl must not be empty'`.
- `cancelUrl === ''` → `'cancelUrl must not be empty'`.

#### `toArray(): array<string, mixed>`

Produces the request body. `failureUrl`, `metadata`, `lineItems`,
`totalDiscount`, `totalTaxAmount`, `subtotalAmount`, and `externalId` are
omitted from the output when not set. `lineItems` are serialised by calling
`LineItem::toArray()` on each entry.

**Example:**

```php
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

$request = new CreateCheckoutRequest(
    amount: 10000,
    currency: 'ZAR',
    successUrl: 'https://shop.example/success',
    cancelUrl:  'https://shop.example/cancel',
    metadata:   ['orderNumber' => 'ORD-1'],
    externalId: 'ORD-1',
);

$request->toArray();
// [
//     'amount' => 10000,
//     'currency' => 'ZAR',
//     'successUrl' => 'https://shop.example/success',
//     'cancelUrl' => 'https://shop.example/cancel',
//     'metadata' => ['orderNumber' => 'ORD-1'],
//     'externalId' => 'ORD-1',
// ]
```

---

### `LineItem`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class LineItem
```

#### Constructor

```php
public function __construct(
    public string $displayName,
    public int $quantity,
    public PricingDetails $pricingDetails,
    public ?string $description = null,
    public ?int $totalDiscount = null,
    public ?int $totalTaxAmount = null,
)
```

**Validation (raises `\InvalidArgumentException`):**

- `displayName === ''` → `'displayName must not be empty'`.
- `quantity < 1` → `'quantity must be at least 1'`.

#### `toArray(): array<string, mixed>`

Always includes `displayName`, `quantity`, and `pricingDetails`. Optional
fields are emitted only when set.

**Example:**

```php
use Sonnenglas\Yoco\Dto\LineItem;
use Sonnenglas\Yoco\Dto\PricingDetails;

new LineItem(
    displayName: 'Sonnenglas Classic 250ml',
    quantity:    2,
    pricingDetails: new PricingDetails(price: 15000),
    description: 'Solar lamp in a jar',
);
```

---

### `PricingDetails`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class PricingDetails
```

#### Constructor

```php
public function __construct(public int $price)
```

**Validation (raises `\InvalidArgumentException`):**

- `price < 0` → `'price must not be negative'`. (Zero is allowed — useful
  for free items inside a paid checkout.)

#### `toArray(): array{price: int}`

```php
new PricingDetails(price: 15000)->toArray();
// ['price' => 15000]
```

---

## Response DTOs

### `CheckoutResponse`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class CheckoutResponse
```

#### Properties

| Property | Type | Always set? | Description |
|----------|------|-------------|-------------|
| `id` | `string` | yes | Checkout id, e.g. `ch_9LVKD8GnAj7f39DFbn4F16bE`. |
| `redirectUrl` | `string` | yes | URL to redirect the customer to. |
| `status` | `string` | yes | Lifecycle status (`'created'`, etc.). |
| `amount` | `int` | yes | Amount in cents. |
| `currency` | `string` | yes | `'ZAR'`. |
| `paymentId` | `?string` | after payment | `p_…` — joins to Yoco payments. |
| `processingMode` | `?string` | usually | `'live'` or `'test'`. |
| `merchantId` | `?string` | usually | Merchant id. |
| `clientReferenceId` | `?string` | optional | Echo of the request's `externalId`. |

#### `fromArray(array $data): self`

Validates that `id`, `redirectUrl`, `status`, and `currency` are strings,
that `amount` is an `int`, and that any present optional field is a string.
Throws `ApiException` (with the raw `$data` on `$responseBody`) on any
mismatch.

**Example — parsing a raw response:**

```php
use Sonnenglas\Yoco\Dto\CheckoutResponse;

$response = CheckoutResponse::fromArray([
    'id' => 'ch_123',
    'redirectUrl' => 'https://pay.yoco.com/ch_123',
    'status' => 'created',
    'amount' => 10000,
    'currency' => 'ZAR',
    'paymentId' => null,
    'processingMode' => 'test',
]);

echo $response->processingMode;             // 'test'
```

---

### `RefundResponse`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class RefundResponse
```

#### Properties

| Property | Type | Always set? | Description |
|----------|------|-------------|-------------|
| `id` | `string` | yes | Refund id, e.g. `rf_…`. |
| `status` | `string` | yes | Lifecycle status (`'created'`, `'succeeded'`, etc.). |
| `amount` | `int` | yes | Refunded amount in cents. |
| `currency` | `string` | yes | `'ZAR'`. |
| `checkoutId` | `?string` | usually | Back-reference to the parent checkout. |
| `paymentId` | `?string` | usually | Back-reference to the underlying payment. |

#### `fromArray(array $data): self`

Same validation pattern as `CheckoutResponse`. Throws `ApiException` on a
malformed payload.

**Example:**

```php
use Sonnenglas\Yoco\Dto\RefundResponse;

$refund = RefundResponse::fromArray([
    'id' => 'rf_abc',
    'status' => 'succeeded',
    'amount' => 2500,
    'currency' => 'ZAR',
    'checkoutId' => 'ch_123',
    'paymentId' => 'p_xyz',
]);
```

---

### `WebhookSubscription`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class WebhookSubscription
```

#### Properties

| Property | Type | Always set? | Description |
|----------|------|-------------|-------------|
| `id` | `string` | yes | Subscription id (`wh_…`). |
| `name` | `string` | yes | Human label. |
| `url` | `string` | yes | Destination URL. |
| `mode` | `string` | yes | `'live'` or `'test'`. |
| `secret` | `?string` | only on `create()` | `whsec_…` value. **Never** returned by `list()`. |

#### `fromArray(array $data): self`

Throws `ApiException` if any of `id`, `name`, `url`, `mode` is missing or
not a string. `secret` may be `null` (always the case for `list()` items)
but must be a string when present.

#### `__debugInfo(): array`

`var_dump`, `print_r`, and Symfony VarDumper read this method when present.
The override **redacts** the secret to `'***redacted***'` if set
(`null` is preserved as `null`) so that the secret does not leak into logs
or developer tooling.

```php
$sub = WebhookSubscription::fromArray([
    'id' => 'wh_123',
    'name' => 'prod',
    'url' => 'https://example.com/hooks',
    'mode' => 'live',
    'secret' => 'whsec_AbCd123',
]);

var_dump($sub);
// object(...)#1 (5) {
//   ["id"]=> string(6) "wh_123"
//   ["name"]=> string(4) "prod"
//   ["url"]=> string(...)
//   ["mode"]=> string(4) "live"
//   ["secret"]=> string(14) "***redacted***"
// }
```

---

### `WebhookEvent`

```php
namespace Sonnenglas\Yoco\Dto;

final readonly class WebhookEvent
```

The return value of `SignatureVerifier::verify()`. Unlike the API response
DTOs, `WebhookEvent` has **no** `fromArray()` — it is only ever produced by
the verifier, which has already validated the field shapes.

#### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | Unique event id. Use this for idempotent processing — Yoco may redeliver the same event. |
| `type` | `string` | Event type, e.g. `'payment.succeeded'`, `'payment.failed'`. |
| `createdDate` | `string` | ISO-8601 timestamp string assigned by Yoco. |
| `payload` | `array<string, mixed>` | Raw event payload. **Not** wrapped in a typed object — Yoco's payload shape varies between event types. Refer to the [Yoco developer docs](https://developer.yoco.com/) for the schema per event type. |

#### Example payload shapes

`payment.succeeded` typically includes:

```php
$event->payload === [
    'id'       => 'p_abc',                  // payment id
    'amount'   => 10000,                    // cents
    'currency' => 'ZAR',
    'status'   => 'succeeded',
    'metadata' => [
        'orderNumber' => 'ORD-100',         // your metadata round-trips here
        'checkoutId'  => 'ch_…',
    ],
    // …other Yoco-specific fields
];
```

`payment.failed` follows the same shape with `'status' => 'failed'` and
typically an additional failure-reason field.

**Use `WebhookEvent::$id` to deduplicate.** Yoco can redeliver the same
event id; your handler should treat second-and-later deliveries as no-ops.

```php
if ($processedEvents->has($event->id)) {
    return; // already handled
}

$processedEvents->record($event->id);
handle($event);
```

## See also

- [`Resources\Checkouts`](checkouts.md) — methods that consume / produce
  these DTOs.
- [`Resources\Webhooks`](webhooks.md) — produces `WebhookSubscription`.
- [`Webhook\SignatureVerifier`](signature-verifier.md) — produces
  `WebhookEvent`.
- [Exceptions](exceptions.md) — what `fromArray()` throws on a malformed
  payload.
