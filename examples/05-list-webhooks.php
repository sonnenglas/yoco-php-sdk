<?php

/**
 * Example: list every webhook subscription on the account.
 *
 * Usage:
 *   YOCO_SECRET_KEY=sk_test_xxx php examples/05-list-webhooks.php
 *
 * What it does:
 *   Fetches every subscription via GET /api/webhooks and prints them as a
 *   simple table. The secret is NOT returned by the list endpoint — Yoco
 *   only surfaces it at creation time — so this is a safe operation to run
 *   from a shared admin terminal.
 *
 * Expected output:
 *   #   id                                          mode    name        url
 *   --  ------------------------------------------  ------  ----------  -------------------------------------
 *   1   wsub_TEST_xxxxxxxxxxxxxxxxxxxxxxxx           test    production  https://example.com/webhooks/yoco
 *   2   wsub_LIVE_yyyyyyyyyyyyyyyyyyyyyyyy           live    production  https://example.com/webhooks/yoco
 *
 *   (or "no subscriptions found" if you have not registered any yet)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Exceptions\YocoException;

$secretKey = getenv('YOCO_SECRET_KEY');

if (! is_string($secretKey) || $secretKey === '') {
    fwrite(STDERR, "YOCO_SECRET_KEY is not set. Export your sk_test_* key first:\n");
    fwrite(STDERR, "  export YOCO_SECRET_KEY=sk_test_your_key_here\n");
    exit(1);
}

$client = new Client(secretKey: $secretKey);

try {
    $subscriptions = $client->webhooks()->list();
} catch (YocoException $e) {
    fwrite(STDERR, "Yoco API call failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($subscriptions === []) {
    echo "No webhook subscriptions found on this account.\n";
    echo "Register one with examples/04-register-webhook.php.\n";
    exit(0);
}

printf("%-3s  %-44s  %-6s  %-12s  %s\n", '#', 'id', 'mode', 'name', 'url');
printf("%-3s  %-44s  %-6s  %-12s  %s\n", '--', str_repeat('-', 44), '------', '------------', str_repeat('-', 40));

foreach ($subscriptions as $index => $sub) {
    printf(
        "%-3d  %-44s  %-6s  %-12s  %s\n",
        $index + 1,
        $sub->id,
        $sub->mode,
        $sub->name,
        $sub->url,
    );
}
