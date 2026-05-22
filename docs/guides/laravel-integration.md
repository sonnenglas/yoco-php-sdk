# Laravel integration

A concrete pattern for using `sonnenglas/yoco-php-sdk` in a Laravel
application: container wiring, config layout, controller, queued
processing, and an idempotent setup command.

This guide assumes Laravel 10 or newer. The SDK has no Laravel-specific
code — everything below is plain Laravel that happens to consume a
framework-agnostic library.

## Config

Create `config/yoco.php`:

```php
<?php

return [
    'secret_key' => env('YOCO_SECRET_KEY'),

    'webhook' => [
        'secret' => env('YOCO_WEBHOOK_SECRET'),
        'name'   => env('YOCO_WEBHOOK_NAME', 'production'),
        'url'    => env('YOCO_WEBHOOK_URL'),
    ],
];
```

In `.env`:

```dotenv
YOCO_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
YOCO_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
YOCO_WEBHOOK_URL=https://example.com/webhooks/yoco
YOCO_WEBHOOK_NAME=production
```

Remember the SDK rule: only read environment variables inside config
files. In your application code use `config('yoco.secret_key')`, not
`env('YOCO_SECRET_KEY')`.

## Service provider

Bind both the SDK client and the signature verifier as singletons so the
container creates each only once per request:

```php
<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

final class YocoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static function (Application $app): Client {
            $secret = $app['config']->get('yoco.secret_key');

            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('YOCO_SECRET_KEY is not configured');
            }

            return new Client(secretKey: $secret);
        });

        $this->app->singleton(SignatureVerifier::class, static function (Application $app): SignatureVerifier {
            $secret = $app['config']->get('yoco.webhook.secret');

            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('YOCO_WEBHOOK_SECRET is not configured');
            }

            return new SignatureVerifier(secret: $secret);
        });
    }
}
```

Register it in `config/app.php` (or `bootstrap/providers.php` on Laravel
11+).

## Controller

The receiver is deliberately thin: verify, dedupe, dispatch, respond.

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessYocoWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

final class YocoWebhookController
{
    public function __invoke(Request $request, SignatureVerifier $verifier): JsonResponse
    {
        try {
            $event = $verifier->verify(
                rawBody: $request->getContent(),
                headers: $request->headers->all(),
            );
        } catch (SignatureVerificationException) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Dedupe — Yoco may retry on timeout.
        $key = "yoco:event:{$event->id}";
        if (! Cache::add($key, 1, now()->addDays(7))) {
            return response()->json(['ok' => true]);
        }

        ProcessYocoWebhook::dispatch(
            eventId:   $event->id,
            type:      $event->type,
            createdAt: $event->createdDate,
            payload:   $event->payload,
        );

        return response()->json(['ok' => true]);
    }
}
```

Route (in `routes/web.php` — webhook endpoints typically live outside
`api/` to avoid Sanctum / throttling middleware accidentally interfering):

```php
use App\Http\Controllers\YocoWebhookController;

Route::post('/webhooks/yoco', YocoWebhookController::class)
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhooks.yoco');
```

Don't forget to add the route to `$except` in `VerifyCsrfToken` or
disable CSRF specifically on this route — webhooks do not have CSRF
tokens.

## Job

```php
<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessYocoWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $orderNumber = $this->payload['metadata']['orderNumber'] ?? null;

        if (! is_string($orderNumber)) {
            return;
        }

        $order = Order::firstWhere('number', $orderNumber);

        if ($order === null) {
            return;
        }

        match ($this->type) {
            'payment.succeeded' => $order->markPaid(
                paymentId: $this->payload['id'] ?? null,
                amount:    $this->payload['amount'] ?? null,
            ),
            'payment.failed'    => $order->markPaymentFailed(
                reason: $this->payload['failureReason'] ?? null,
            ),
            default             => null, // unknown event type — log if you like
        };
    }
}
```

## Setup command

Make registering the webhook idempotent so you can run it every deploy
without accumulating duplicates:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Sonnenglas\Yoco\Client;

final class YocoRegisterWebhook extends Command
{
    protected $signature = 'yoco:register-webhook';

    protected $description = 'Register (or re-use) the Yoco webhook subscription for this environment';

    public function handle(Client $client): int
    {
        $url  = (string) config('yoco.webhook.url');
        $name = (string) config('yoco.webhook.name');

        if ($url === '') {
            $this->error('YOCO_WEBHOOK_URL is not configured.');
            return self::FAILURE;
        }

        foreach ($client->webhooks()->list() as $existing) {
            if ($existing->url === $url && $existing->name === $name) {
                $this->info("Webhook already registered: id={$existing->id} mode={$existing->mode}");
                return self::SUCCESS;
            }
        }

        $created = $client->webhooks()->create(name: $name, url: $url);

        $this->info("Created webhook subscription id={$created->id} mode={$created->mode}");
        $this->warn('The secret below is shown ONCE. Persist it in YOCO_WEBHOOK_SECRET immediately:');
        $this->line($created->secret ?? '(missing — investigate)');

        return self::SUCCESS;
    }
}
```

Run it from your deploy script:

```bash
php artisan yoco:register-webhook
```

For a richer implementation (rotation, multi-environment routing,
secret-store integration), extend the command with your own conventions.

## Common pitfalls

- **Forgetting the CSRF exemption.** Yoco does not send CSRF tokens.
  Leaving CSRF on will cause silent 419s.
- **Reading the body twice.** Calling `$request->all()` or `$request->json()`
  before `$request->getContent()` may consume the stream in some setups.
  Read the raw content first.
- **Using `env()` outside config.** Cached configs (`php artisan
  config:cache`) make `env()` return `null` at runtime.
- **Running the queue with `database` driver and forgetting to migrate
  jobs table.** Webhook events will pile up unprocessed.

## Next steps

- [Webhook handling](webhook-handling.md) — the framework-agnostic version
  of this guide.
- [Testing](testing.md) — how to drive the controller in feature tests
  without a real Yoco delivery.
- [Plain PHP](plain-php.md) / [Symfony integration](symfony-integration.md)
  — equivalent patterns for non-Laravel stacks.
