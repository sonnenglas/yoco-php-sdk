# Symfony integration

A concrete pattern for using `sonnenglas/yoco-php-sdk` in a Symfony
application: service definitions, controller, Messenger handler, and a
console command to register the webhook subscription.

This guide assumes Symfony 6.4 / 7.x. The SDK has no Symfony-specific
code — everything below is plain Symfony that happens to consume a
framework-agnostic library.

## Environment

In `.env` (and `.env.local` for secrets):

```dotenv
YOCO_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
YOCO_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
YOCO_WEBHOOK_URL=https://example.com/webhooks/yoco
YOCO_WEBHOOK_NAME=production
```

## Service definitions

In `config/services.yaml`:

```yaml
services:
    Sonnenglas\Yoco\Client:
        arguments:
            $secretKey: '%env(YOCO_SECRET_KEY)%'

    Sonnenglas\Yoco\Webhook\SignatureVerifier:
        arguments:
            $secret: '%env(YOCO_WEBHOOK_SECRET)%'
```

That's it for wiring. `php-http/discovery` picks the PSR-18 client
(Symfony HttpClient already counts) and PSR-17 factories automatically.
If you prefer to be explicit and use Symfony HttpClient directly:

```yaml
services:
    Sonnenglas\Yoco\Client:
        arguments:
            $secretKey: '%env(YOCO_SECRET_KEY)%'
            $httpClient: '@Symfony\Component\HttpClient\Psr18Client'
            $requestFactory: '@Nyholm\Psr7\Factory\Psr17Factory'
            $streamFactory: '@Nyholm\Psr7\Factory\Psr17Factory'

    Symfony\Component\HttpClient\Psr18Client: ~

    Nyholm\Psr7\Factory\Psr17Factory: ~
```

(`composer require symfony/http-client nyholm/psr7` if you do not already
have those.)

## Controller

The receiver verifies the signature, dedupes, and dispatches a message —
nothing else. Slow work belongs in the Messenger handler.

```php
<?php

namespace App\Controller;

use App\Message\ProcessYocoWebhook;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class YocoWebhookController
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly MessageBusInterface $bus,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/webhooks/yoco', methods: ['POST'], name: 'yoco_webhook')]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $event = $this->verifier->verify(
                rawBody: $request->getContent(),
                headers: $request->headers->all(),
            );
        } catch (SignatureVerificationException) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        // Dedupe via the cache. The closure runs only on a cache miss,
        // i.e. the first time we see this event.
        $isNew = false;
        $this->cache->get("yoco_event_{$event->id}", static function (ItemInterface $item) use (&$isNew): int {
            $item->expiresAfter(7 * 86400);
            $isNew = true;

            return 1;
        });

        if ($isNew) {
            $this->bus->dispatch(new ProcessYocoWebhook(
                eventId:   $event->id,
                type:      $event->type,
                createdAt: $event->createdDate,
                payload:   $event->payload,
            ));
        }

        return new JsonResponse(['ok' => true]);
    }
}
```

## Messenger message + handler

`src/Message/ProcessYocoWebhook.php`:

```php
<?php

namespace App\Message;

final readonly class ProcessYocoWebhook
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $createdAt,
        public array $payload,
    ) {}
}
```

`src/MessageHandler/ProcessYocoWebhookHandler.php`:

```php
<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\ProcessYocoWebhook;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessYocoWebhookHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(ProcessYocoWebhook $message): void
    {
        $orderNumber = $message->payload['metadata']['orderNumber'] ?? null;

        if (! is_string($orderNumber)) {
            return;
        }

        $order = $this->em->getRepository(Order::class)->findOneBy(['number' => $orderNumber]);

        if ($order === null) {
            return;
        }

        match ($message->type) {
            'payment.succeeded' => $order->markPaid(
                paymentId: $message->payload['id'] ?? null,
                amount:    $message->payload['amount'] ?? null,
            ),
            'payment.failed'    => $order->markPaymentFailed(
                reason: $message->payload['failureReason'] ?? null,
            ),
            default             => null,
        };

        $this->em->flush();
    }
}
```

Make sure your `framework.yaml` routes the message to an async transport
so the controller does not block on the handler:

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            App\Message\ProcessYocoWebhook: async
```

## Console command

Idempotent webhook registration — safe to run on every deploy:

```php
<?php

namespace App\Command;

use Sonnenglas\Yoco\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'yoco:register-webhook', description: 'Register or reuse the Yoco webhook subscription')]
final class RegisterYocoWebhookCommand extends Command
{
    public function __construct(
        private readonly Client $yoco,
        private readonly string $webhookUrl,
        private readonly string $webhookName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->webhookUrl === '') {
            $io->error('YOCO_WEBHOOK_URL is not configured.');

            return Command::FAILURE;
        }

        foreach ($this->yoco->webhooks()->list() as $existing) {
            if ($existing->url === $this->webhookUrl && $existing->name === $this->webhookName) {
                $io->success("Webhook already registered: id={$existing->id} mode={$existing->mode}");

                return Command::SUCCESS;
            }
        }

        $created = $this->yoco->webhooks()->create(name: $this->webhookName, url: $this->webhookUrl);

        $io->success("Created webhook subscription id={$created->id} mode={$created->mode}");
        $io->warning('The secret below is shown ONCE. Persist it in YOCO_WEBHOOK_SECRET immediately:');
        $io->writeln($created->secret ?? '(missing — investigate)');

        return Command::SUCCESS;
    }
}
```

Wire the args in `services.yaml`:

```yaml
services:
    App\Command\RegisterYocoWebhookCommand:
        arguments:
            $webhookUrl: '%env(YOCO_WEBHOOK_URL)%'
            $webhookName: '%env(YOCO_WEBHOOK_NAME)%'
```

Run on deploy:

```bash
bin/console yoco:register-webhook
```

## Common pitfalls

- **Letting the firewall guard `/webhooks/yoco`.** The webhook is an
  unauthenticated POST from Yoco's servers. Exclude it from CSRF and
  from any authentication firewall rules.
- **Using `Request::request->all()` or `Request::toArray()` before
  reading raw content.** `getContent()` should be the first thing you
  call on the request inside the controller.
- **Forgetting to expire the dedupe cache entry.** Yoco's retry window
  is short, but unbounded keys will leak memory in Redis over time.
  Seven days is a comfortable default.
- **Routing the message to the `sync` transport.** That would defeat
  the point — the handler would run inside the HTTP request and could
  time out against Yoco.

## Next steps

- [Webhook handling](webhook-handling.md) — the framework-agnostic
  version of this guide.
- [Testing](testing.md) — patterns for testing the controller and
  handler in isolation.
- [Laravel integration](laravel-integration.md) /
  [Plain PHP](plain-php.md) — equivalent patterns for other stacks.
