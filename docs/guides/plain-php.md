# Plain PHP

How to use `sonnenglas/yoco-php-sdk` without a framework — either with
Slim as a micro-framework, or with pure PHP via the built-in web server.

## Project layout

```
project/
├── composer.json
├── public/
│   ├── index.php       # front controller (Slim) OR direct entry points
│   └── webhook.php     # for pure-PHP deployments without a router
├── src/
│   └── Receiver.php    # business logic — testable, framework-free
└── vendor/
```

If you are using Slim, your front controller is `public/index.php`. If
you are deploying as a flat collection of PHP files behind Apache or
Nginx, `public/webhook.php` becomes its own endpoint.

## Setup

```bash
mkdir yoco-receiver && cd yoco-receiver
composer init --no-interaction --name="example/yoco-receiver"
composer require sonnenglas/yoco-php-sdk guzzlehttp/guzzle
```

Optional, if you want Slim:

```bash
composer require slim/slim slim/psr7
```

## Slim 4 webhook receiver

`public/index.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

$secret = getenv('YOCO_WEBHOOK_SECRET');

if (! is_string($secret) || $secret === '') {
    throw new RuntimeException('YOCO_WEBHOOK_SECRET is not configured.');
}

$verifier = new SignatureVerifier(secret: $secret);

$app = AppFactory::create();

$app->post('/webhooks/yoco', static function (
    ServerRequestInterface $request,
    Response $response,
) use ($verifier): ResponseInterface {
    $rawBody = (string) $request->getBody();
    $headers = $request->getHeaders();

    try {
        $event = $verifier->verify($rawBody, $headers);
    } catch (SignatureVerificationException) {
        $response->getBody()->write('{"error":"unauthorized"}');

        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    // TODO: dedupe + enqueue. For a minimal demo, just log and ack.
    error_log(sprintf(
        '[yoco] event id=%s type=%s order=%s',
        $event->id,
        $event->type,
        $event->payload['metadata']['orderNumber'] ?? '(none)',
    ));

    $response->getBody()->write('{"ok":true}');

    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

$app->run();
```

Run locally with the PHP built-in server:

```bash
YOCO_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxx php -S 127.0.0.1:8080 -t public
```

Yoco will not be able to reach `127.0.0.1` directly — for a real test
delivery use [ngrok](https://ngrok.com/), `cloudflared tunnel`, or
similar to publish your local port.

## Pure PHP (no router)

If you do not want Slim, drop the receiver into a flat `webhook.php`
file. The PSR side of the SDK does not change; only the request handling
does.

`public/webhook.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$secret = getenv('YOCO_WEBHOOK_SECRET');

if (! is_string($secret) || $secret === '') {
    http_response_code(500);
    error_log('YOCO_WEBHOOK_SECRET is not configured.');
    exit;
}

$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

// Fallback for SAPIs without getallheaders() — synthesise from $_SERVER.
if ($headers === []) {
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
} catch (SignatureVerificationException) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"unauthorized"}';
    exit;
}

// Minimal receiver — log and ack. Add dedupe + async dispatch for prod.
error_log(sprintf(
    '[yoco] event id=%s type=%s',
    $event->id,
    $event->type,
));

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
```

Serve it with:

```bash
YOCO_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxx php -S 127.0.0.1:8080 -t public
```

Or behind Nginx with a location block:

```nginx
location /webhooks/yoco {
    try_files /dev/null @php;
}

location @php {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/yoco-receiver/public/webhook.php;
    include fastcgi_params;
}
```

## Creating checkouts

Reusing the SDK to create checkouts in plain PHP looks identical to the
[quickstart](quickstart.md). For example, a CLI script that mints a
checkout link for a customer:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;

$secret = getenv('YOCO_SECRET_KEY') ?: exit("YOCO_SECRET_KEY is required\n");

$client = new Client(secretKey: $secret);

$checkout = $client->checkouts()->create(new CreateCheckoutRequest(
    amount:     5000,
    currency:   'ZAR',
    successUrl: 'https://example.com/payment/success',
    cancelUrl:  'https://example.com/payment/cancel',
    metadata:   ['orderNumber' => 'ORD-100'],
));

echo "Open this URL to pay:\n";
echo $checkout->redirectUrl, PHP_EOL;
```

## Common pitfalls

- **Reading the body too late.** `file_get_contents('php://input')` is
  one-shot in some SAPIs. Read it first thing, store it in a variable,
  and pass that variable to the verifier.
- **Missing `getallheaders()`.** It is bundled with mod_php and PHP-FPM
  but is undefined for some CLI / FastCGI SAPIs. The fallback shown
  above (iterating `$_SERVER['HTTP_*']`) covers those cases.
- **Using `php://stdin` instead of `php://input`.** `stdin` is a CLI
  concept; webhooks come through `input`.
- **Buffering output.** `ob_start()` followed by errors can swallow your
  401 response. Either avoid output buffering on the webhook endpoint or
  explicitly call `ob_end_clean()` before responding.

## Next steps

- [Quickstart](quickstart.md) — the redirect flow.
- [Webhook handling](webhook-handling.md) — the dedupe + async pattern
  the example above intentionally skips.
- [Testing](testing.md) — sign your own payloads to drive the receiver
  from PHPUnit.
- [`examples/03-handle-webhook.php`](../../examples/03-handle-webhook.php) —
  same idea as `public/webhook.php` above, packaged as a runnable
  example file in this repository.
