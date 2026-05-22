# Quickstart

Build your first Yoco checkout in five minutes. This guide assumes you have
already followed the [installation guide](installation.md) and that you have a
Yoco **test secret key** (`sk_test_...`) on hand — grab one from the
[Yoco merchant dashboard](https://merchant.yoco.com/) under **Developers →
API keys**.

## Why

Yoco's Online Payments API is a **hosted-checkout** flow: you create a checkout
session via the API, redirect the customer to the URL Yoco gives back, and the
customer enters their card details on Yoco's domain. After the payment
completes (or fails), Yoco redirects the customer to a URL you control and
fires a webhook for server-to-server confirmation.

This guide covers the first half — creating the session and sending the
customer to it. For receiving the webhook, see
[Webhook handling](webhook-handling.md).

## How

### Step 1. Set your secret key

Export it once in your shell so you do not paste it into source code:

```bash
export YOCO_SECRET_KEY=sk_test_your_key_here
```

### Step 2. Write the script

Create `checkout.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

// 1. Construct the SDK client. The secret key is the only required argument;
//    everything else (HTTP client, PSR-17 factories) is auto-discovered.
$client = new Client(secretKey: getenv('YOCO_SECRET_KEY'));

// 2. Build the request. All amounts are in cents — 5000 = R50.00.
//    Currency must be 'ZAR' (the only currency Yoco supports today).
//    successUrl / cancelUrl must be absolute URLs that you control.
$request = new CreateCheckoutRequest(
    amount:     5000,
    currency:   'ZAR',
    successUrl: 'https://example.com/payment/success',
    cancelUrl:  'https://example.com/payment/cancel',
    metadata:   ['orderNumber' => 'ORD-100'],
);

// 3. Call the API. The SDK auto-generates an Idempotency-Key (UUID v4) so
//    that retrying this exact call is safe — Yoco will return the original
//    response and will NOT create a duplicate checkout.
$checkout = $client->checkouts()->create($request);

// 4. Inspect what came back.
echo "Checkout id:      {$checkout->id}\n";
echo "Payment id:       " . ($checkout->paymentId ?? 'pending') . "\n";
echo "Processing mode:  " . ($checkout->processingMode ?? 'unknown') . "\n";
echo "Status:           {$checkout->status}\n";
echo "Redirect URL:     {$checkout->redirectUrl}\n";
```

### Step 3. Run it

```bash
php checkout.php
```

Expected output (your ids will differ):

```
Checkout id:      ch_xxxxxxxxxxxxxxxxxxxxxxxx
Payment id:       pending
Processing mode:  test
Status:           created
Redirect URL:     https://pay.yoco.com/r/xxxxxxxx
```

### Step 4. Open the redirect URL in your browser

Copy the `Redirect URL` from the script output and open it in a browser. You
should see Yoco's hosted payment page.

Pay with the official test card:

| Field        | Value                  |
|--------------|------------------------|
| Card number  | `4111 1111 1111 1111`  |
| Expiry       | Any future month/year  |
| CVC          | Any 3 digits           |

Full list of test cards (including 3-D Secure flows, declined cards, and
network-specific scenarios) is in the
[Yoco testing docs](https://developer.yoco.com/docs/checkout-api/testing).

### Step 5. Confirm you are in test mode

Yoco reports the test / live distinction in two places:

- **`$checkout->processingMode`** — `'test'` when called with an `sk_test_*`
  key, `'live'` with an `sk_live_*` key.
- **Yoco dashboard** — test transactions do **not** appear under Sales. If you
  want to see them, switch the dashboard into Test mode (top-right toggle).

If `processingMode` came back as `'test'`, you are wired up correctly and any
charges you make are simulated. No real card is ever charged with an
`sk_test_*` key.

## What to do with the redirect URL in production

In a real web app you do not print the URL — you send the customer to it. The
exact pattern depends on whether the checkout is created via a server-rendered
form post or via an AJAX call from your storefront:

```php
// Server-rendered: 302 the customer straight to Yoco.
header('Location: ' . $checkout->redirectUrl, true, 302);
exit;
```

```php
// JSON API: return the URL and have your frontend window.location.assign().
header('Content-Type: application/json');
echo json_encode([
    'checkoutId'  => $checkout->id,
    'redirectUrl' => $checkout->redirectUrl,
], JSON_THROW_ON_ERROR);
```

Persist `$checkout->id` against your local order **before** redirecting. You
will need it later to:

- Match the incoming webhook (`event.payload.metadata.checkoutId`) to your
  order.
- Issue refunds via `$client->checkouts()->refund($checkoutId, ...)`.

## Common pitfalls

- **Amounts in cents, not rands.** `amount: 5000` is R50.00. Passing `50`
  would mean 50 cents — and would be rejected because Yoco's minimum is
  `200` cents (R2.00).
- **Only `ZAR`.** The SDK rejects anything else at construction time with an
  `InvalidArgumentException`.
- **Absolute URLs only.** `successUrl` and `cancelUrl` must be reachable from
  the customer's browser — `http://localhost` will not work in production
  (it will when you run the test card flow locally).
- **Do not log the redirect URL into a customer-visible channel for someone
  else's checkout.** Anyone with the link can drive that specific checkout to
  completion.

## Next steps

- [Webhook handling](webhook-handling.md) — set up your webhook receiver so
  your application learns about payment success / failure server-side.
- [Error handling](error-handling.md) — what to do when Yoco returns 4xx /
  5xx.
- [Testing](testing.md) — mock the SDK in unit tests.
- [Examples](../../examples/) — runnable scripts for the full set of API
  operations.
