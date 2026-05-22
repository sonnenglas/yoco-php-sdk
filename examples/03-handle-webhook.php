<?php

/**
 * Example: minimal webhook receiver.
 *
 * Usage:
 *   Serve this file via PHP's built-in web server (or any FastCGI host) and
 *   point a Yoco webhook subscription at the public URL:
 *
 *     YOCO_WEBHOOK_SECRET=whsec_xxx \
 *       php -S 127.0.0.1:8080 examples/03-handle-webhook.php
 *
 *   For Yoco to actually reach your local port, tunnel it with ngrok or
 *   cloudflared:
 *
 *     ngrok http 8080
 *
 *   …and register the resulting https://*.ngrok.io URL with
 *   examples/04-register-webhook.php.
 *
 * What it does:
 *   Reads the raw POST body, verifies the Standard Webhooks signature, and
 *   prints a one-line summary of the event. Returns 200 on success, 401 on
 *   signature failure, 405 on non-POST.
 *
 *   This is intentionally minimal — for production add dedupe (against
 *   $event->id) and dispatch the work to a queue. See
 *   docs/guides/webhook-handling.md.
 *
 * Expected output (server log on a verified delivery):
 *   [yoco] OK event=evt_xxxxx type=payment.succeeded order=ORD-DEMO-001
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

// Only POST is accepted. The built-in server will also serve this script
// on GET requests (e.g. browser sanity check); reject those with 405.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain');
    echo "This endpoint accepts POST requests only.\n";
    exit;
}

$secret = getenv('YOCO_WEBHOOK_SECRET');

if (! is_string($secret) || $secret === '') {
    http_response_code(500);
    error_log('YOCO_WEBHOOK_SECRET is not configured.');
    header('Content-Type: application/json');
    echo '{"error":"server not configured"}';
    exit;
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":"empty request body"}';
    exit;
}

// getallheaders() is available under mod_php and PHP-FPM. Provide a
// fallback so this script also works under PHP's built-in CLI server,
// where the function may be absent on older PHP builds.
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headerName = strtolower(str_replace('_', '-', substr($name, 5)));
            $headers[$headerName] = $value;
        }
    }
}

$verifier = new SignatureVerifier(secret: $secret);

try {
    $event = $verifier->verify($rawBody, $headers);
} catch (SignatureVerificationException $e) {
    error_log('[yoco] signature verification failed: ' . $e->getMessage());
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"unauthorized"}';
    exit;
}

$orderNumber = $event->payload['metadata']['orderNumber'] ?? '(none)';

error_log(sprintf(
    '[yoco] OK event=%s type=%s order=%s',
    $event->id,
    $event->type,
    is_string($orderNumber) ? $orderNumber : '(non-string)',
));

// Production note: at this point you would:
//   1. Reject the request if you have already processed $event->id (dedupe).
//   2. Push the event onto a queue rather than processing inline.
//   3. Return 200 only after step 2 succeeded.
// See docs/guides/webhook-handling.md for the full pattern.

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
