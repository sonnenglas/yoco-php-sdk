<?php

/**
 * Example: refund a checkout (full or partial).
 *
 * Usage:
 *   Full refund:
 *     YOCO_SECRET_KEY=sk_xxx php examples/06-refund.php ch_xxxxxxxxxxxxxxxxxxxx
 *
 *   Partial refund (amount in cents):
 *     YOCO_SECRET_KEY=sk_xxx php examples/06-refund.php ch_xxxxxxxxxxxxxxxxxxxx 1500
 *
 * What it does:
 *   POSTs to /api/checkouts/{checkoutId}/refund. Passing only the checkout id
 *   refunds the full original amount; passing an additional integer (cents)
 *   issues a partial refund for that amount.
 *
 *   The SDK generates an Idempotency-Key automatically — if you re-run the
 *   script with the same arguments, the SDK will pick a fresh UUID v4 each
 *   time, which means Yoco will create separate refund records. To make a
 *   retry safe (same key, same response), pass the key as the third
 *   argument:
 *
 *     YOCO_SECRET_KEY=sk_xxx php examples/06-refund.php ch_xxx 1500 my-key-v1
 *
 * IMPORTANT: refunds are NOT supported in Yoco test mode. To exercise this
 * code path in tests, mock the HTTP client — see docs/guides/testing.md.
 *
 * Expected output:
 *   Refund created:
 *     id:          rfd_xxxxxxxxxxxxxxxxxxxxxxxx
 *     status:      created
 *     amount:      1500 ZAR cents
 *     checkoutId:  ch_xxxxxxxxxxxxxxxxxxxxxxxx
 *     paymentId:   pay_xxxxxxxxxxxxxxxxxxxxxxxx
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Exceptions\YocoException;

$secretKey = getenv('YOCO_SECRET_KEY');

if (! is_string($secretKey) || $secretKey === '') {
    fwrite(STDERR, "YOCO_SECRET_KEY is not set. Export your sk_live_* key first:\n");
    fwrite(STDERR, "  export YOCO_SECRET_KEY=sk_live_your_key_here\n");
    fwrite(STDERR, "Note: Yoco test mode does NOT support refunds.\n");
    exit(1);
}

$checkoutId = $argv[1] ?? '';

if (! is_string($checkoutId) || $checkoutId === '') {
    fwrite(STDERR, "Missing checkout id. Pass it as the first argument:\n");
    fwrite(STDERR, "  php examples/06-refund.php ch_xxxxxxxxxxxxxxxxxxxx [amount-in-cents] [idempotency-key]\n");
    exit(1);
}

$amount = null;
if (isset($argv[2]) && $argv[2] !== '') {
    if (! ctype_digit((string) $argv[2])) {
        fwrite(STDERR, "Amount must be a positive integer (cents). Got: {$argv[2]}\n");
        exit(1);
    }
    $amount = (int) $argv[2];
}

$idempotencyKey = $argv[3] ?? null;

$client = new Client(secretKey: $secretKey);

try {
    $refund = $client->checkouts()->refund(
        checkoutId:     $checkoutId,
        amount:         $amount,
        idempotencyKey: $idempotencyKey,
    );
} catch (YocoException $e) {
    fwrite(STDERR, "Yoco API call failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Refund created:\n";
echo "  id:          {$refund->id}\n";
echo "  status:      {$refund->status}\n";
echo "  amount:      {$refund->amount} {$refund->currency} cents\n";
echo "  checkoutId:  " . ($refund->checkoutId ?? '(not returned)') . "\n";
echo "  paymentId:   " . ($refund->paymentId ?? '(not returned)') . "\n";
