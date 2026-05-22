<?php

/**
 * Example: full checkout with line items, pricing details, discounts, tax,
 * external id, and a user-supplied Idempotency-Key.
 *
 * Usage:
 *   YOCO_SECRET_KEY=sk_test_xxx php examples/02-create-checkout-with-line-items.php
 *
 * What it does:
 *   Builds a CreateCheckoutRequest with two line items (a printed book and an
 *   e-book), a R10 discount, 15% VAT, an externalId tied to your internal
 *   order, and an explicit Idempotency-Key derived from the order number so
 *   that retries are deterministic. Posts it to Yoco and prints the result.
 *
 * Expected output:
 *   Checkout id:      ch_TEST_xxxxxxxxxxxxxxxxxxxx
 *   Status:           created
 *   Amount:           15000 ZAR cents
 *   Redirect URL:     https://pay.yoco.com/r/xxxxxxxx
 *   Idempotency-Key:  ord-demo-002-checkout-v1
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Dto\LineItem;
use Sonnenglas\Yoco\Dto\PricingDetails;
use Sonnenglas\Yoco\Exceptions\YocoException;

$secretKey = getenv('YOCO_SECRET_KEY');

if (! is_string($secretKey) || $secretKey === '') {
    fwrite(STDERR, "YOCO_SECRET_KEY is not set. Export your sk_test_* key first:\n");
    fwrite(STDERR, "  export YOCO_SECRET_KEY=sk_test_your_key_here\n");
    exit(1);
}

$client = new Client(secretKey: $secretKey);

// Build line items. Amounts are always in cents — Yoco does not accept
// decimals.
$printedBook = new LineItem(
    displayName:    'Sonnenglas Stories — Printed Edition',
    quantity:       1,
    pricingDetails: new PricingDetails(price: 10_000),  // R100.00
    description:    'Hardcover, 240 pages.',
);

$ebook = new LineItem(
    displayName:    'Sonnenglas Stories — Digital Edition',
    quantity:       1,
    pricingDetails: new PricingDetails(price: 4_000),   // R40.00
    description:    'PDF + EPUB.',
);

// Pricing maths (in cents):
//   subtotal   = 10_000 + 4_000        = 14_000
//   discount   =                          1_000        (-R10.00)
//   net        = 14_000 - 1_000        = 13_000
//   vat (15%)  = round(13_000 * 0.15)  =  2_000        (rounded to R20.00)
//   total      = 13_000 + 2_000        = 15_000
$subtotal       = 14_000;
$totalDiscount  =  1_000;
$totalTaxAmount =  2_000;
$amount         = ($subtotal - $totalDiscount) + $totalTaxAmount; // 15_000

$orderNumber = 'ORD-DEMO-002';

// Idempotency-Key: any string up to 100 chars that uniquely identifies
// this logical request. A predictable derivation lets the SDK safely
// retry the call without creating a duplicate checkout.
$idempotencyKey = strtolower($orderNumber) . '-checkout-v1';

$request = new CreateCheckoutRequest(
    amount:          $amount,
    currency:        'ZAR',
    successUrl:      "https://example.com/payment/{$orderNumber}/success",
    cancelUrl:       "https://example.com/payment/{$orderNumber}/cancel",
    failureUrl:      "https://example.com/payment/{$orderNumber}/failure",
    metadata:        [
        'orderNumber' => $orderNumber,
        'customerId'  => 'CUST-42',
        'campaign'    => 'launch-week',
    ],
    lineItems:       [$printedBook, $ebook],
    totalDiscount:   $totalDiscount,
    totalTaxAmount:  $totalTaxAmount,
    subtotalAmount:  $subtotal,
    externalId:      $orderNumber,
);

try {
    $checkout = $client->checkouts()->create($request, idempotencyKey: $idempotencyKey);
} catch (YocoException $e) {
    fwrite(STDERR, "Yoco API call failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Checkout id:      {$checkout->id}\n";
echo "Status:           {$checkout->status}\n";
echo "Amount:           {$checkout->amount} {$checkout->currency} cents\n";
echo "Redirect URL:     {$checkout->redirectUrl}\n";
echo "Idempotency-Key:  {$idempotencyKey}\n";
