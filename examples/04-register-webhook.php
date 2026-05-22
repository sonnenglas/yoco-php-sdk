<?php

/**
 * Example: register (or reuse) a webhook subscription.
 *
 * Usage:
 *   YOCO_SECRET_KEY=sk_test_xxx \
 *     php examples/04-register-webhook.php https://example.com/webhooks/yoco [name]
 *
 *   or via environment:
 *
 *   YOCO_SECRET_KEY=sk_test_xxx \
 *     YOCO_WEBHOOK_URL=https://example.com/webhooks/yoco \
 *     YOCO_WEBHOOK_NAME=production \
 *     php examples/04-register-webhook.php
 *
 * What it does:
 *   1. Lists existing webhook subscriptions on the account.
 *   2. If one already matches the (url, name) pair, prints its id and exits.
 *   3. Otherwise creates a new subscription and prints the secret ONCE.
 *
 * The script is idempotent — running it twice will not create duplicates.
 * This is the pattern you want in a deploy script.
 *
 * Expected output (first run):
 *   Created subscription:
 *     id:     wsub_TEST_xxxxxxxxxxxxxxxxxxxxxxxx
 *     name:   production
 *     mode:   test
 *     url:    https://example.com/webhooks/yoco
 *
 *   STORE THIS SECRET NOW — it is returned only once:
 *     whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *
 * Expected output (second run with the same URL):
 *   Subscription already registered:
 *     id:     wsub_TEST_xxxxxxxxxxxxxxxxxxxxxxxx
 *     name:   production
 *     mode:   test
 *     url:    https://example.com/webhooks/yoco
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

$url  = $argv[1] ?? getenv('YOCO_WEBHOOK_URL') ?: '';
$name = $argv[2] ?? getenv('YOCO_WEBHOOK_NAME') ?: 'production';

if (! is_string($url) || $url === '') {
    fwrite(STDERR, "Missing webhook URL. Pass it as the first argument or via YOCO_WEBHOOK_URL:\n");
    fwrite(STDERR, "  php examples/04-register-webhook.php https://example.com/webhooks/yoco\n");
    exit(1);
}

$client = new Client(secretKey: $secretKey);

try {
    foreach ($client->webhooks()->list() as $existing) {
        if ($existing->url === $url && $existing->name === $name) {
            echo "Subscription already registered:\n";
            echo "  id:     {$existing->id}\n";
            echo "  name:   {$existing->name}\n";
            echo "  mode:   {$existing->mode}\n";
            echo "  url:    {$existing->url}\n";

            exit(0);
        }
    }

    $created = $client->webhooks()->create(name: $name, url: $url);
} catch (YocoException $e) {
    fwrite(STDERR, "Yoco API call failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Created subscription:\n";
echo "  id:     {$created->id}\n";
echo "  name:   {$created->name}\n";
echo "  mode:   {$created->mode}\n";
echo "  url:    {$created->url}\n";
echo "\n";
echo "STORE THIS SECRET NOW — it is returned only once:\n";
echo "  " . ($created->secret ?? '(missing — investigate)') . "\n";
