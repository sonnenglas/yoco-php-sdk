<?php

/**
 * Example: minimal checkout creation.
 *
 * Usage:
 *   YOCO_SECRET_KEY=sk_test_xxx php examples/01-create-checkout.php
 *
 * What it does:
 *   Builds the smallest possible CreateCheckoutRequest (amount, currency,
 *   success URL, cancel URL) and POSTs it to the Yoco Checkout API. Prints
 *   the redirect URL the customer would be sent to, plus the checkout id
 *   you should persist against your own order.
 *
 * Expected output:
 *   Checkout id:      ch_TEST_xxxxxxxxxxxxxxxxxxxx
 *   Payment id:       (pending)
 *   Processing mode:  test
 *   Status:           created
 *   Amount:           5000 ZAR cents (= R50.00)
 *   Redirect URL:     https://pay.yoco.com/r/xxxxxxxx
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Exceptions\YocoException;

$secretKey = getenv('YOCO_SECRET_KEY');

if (! is_string($secretKey) || $secretKey === '') {
    fwrite(STDERR, "YOCO_SECRET_KEY is not set. Export your sk_test_* key first:\n");
    fwrite(STDERR, "  export YOCO_SECRET_KEY=sk_test_your_key_here\n");
    exit(1);
}

$client = new Client(secretKey: $secretKey);

$request = new CreateCheckoutRequest(
    amount:     5000,                                  // 5000 cents = R50.00
    currency:   'ZAR',
    successUrl: 'https://example.com/payment/success',
    cancelUrl:  'https://example.com/payment/cancel',
    metadata:   ['orderNumber' => 'ORD-DEMO-001'],
);

try {
    $checkout = $client->checkouts()->create($request);
} catch (YocoException $e) {
    fwrite(STDERR, "Yoco API call failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Checkout id:      {$checkout->id}\n";
echo "Payment id:       " . ($checkout->paymentId ?? '(pending)') . "\n";
echo "Processing mode:  " . ($checkout->processingMode ?? '(unknown)') . "\n";
echo "Status:           {$checkout->status}\n";
echo "Amount:           {$checkout->amount} {$checkout->currency} cents\n";
echo "Redirect URL:     {$checkout->redirectUrl}\n";
