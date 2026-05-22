# API Reference

Complete reference for every public symbol in `sonnenglas/yoco-php-sdk`
(v0.2.0). Every signature, parameter type, exception, and constant on this
page reflects what is actually in `src/`.

## Class index

| Class | Purpose |
|-------|---------|
| [`Sonnenglas\Yoco\Client`](client.md) | Top-level entry point. Wires PSR-18 / PSR-17 dependencies and exposes lazy resource accessors. |
| [`Sonnenglas\Yoco\Resources\Checkouts`](checkouts.md) | Create hosted checkouts and issue full or partial refunds. |
| [`Sonnenglas\Yoco\Resources\Webhooks`](webhooks.md) | Create, list, and delete webhook subscriptions on your Yoco account. |
| [`Sonnenglas\Yoco\Webhook\SignatureVerifier`](signature-verifier.md) | Verify an inbound webhook request against the Standard Webhooks v1 scheme, return a parsed `WebhookEvent`. |
| [DTOs](dtos.md) | `CreateCheckoutRequest`, `LineItem`, `PricingDetails`, `CheckoutResponse`, `RefundResponse`, `WebhookSubscription`, `WebhookEvent`. |
| [Exceptions](exceptions.md) | Full exception hierarchy rooted at `Sonnenglas\Yoco\Exceptions\YocoException`. |

## Architecture

```
                      ┌──────────────────────────────────┐
                      │   Sonnenglas\Yoco\Client         │
                      │  (constructor wires PSR deps)    │
                      └────┬───────────────────────┬─────┘
                           │                       │
                  ┌────────▼─────────┐   ┌─────────▼────────┐
                  │ Resources\       │   │ Resources\       │
                  │   Checkouts      │   │   Webhooks       │
                  └────────┬─────────┘   └─────────┬────────┘
                           │                       │
                           └───────┬───────────────┘
                                   │
                          ┌────────▼─────────┐
                          │  Http\HttpClient │   ← internal, not public API
                          │  (PSR-18 wrapper)│
                          └────────┬─────────┘
                                   │
                                   ▼
                       ┌───────────────────────┐
                       │   Yoco Checkout API   │
                       │ payments.yoco.com/api │
                       └───────────────────────┘


   Inbound webhook from Yoco
              │
              ▼
   ┌──────────────────────────┐         ┌─────────────────────────┐
   │ Webhook\SignatureVerifier│ ──────► │  Dto\WebhookEvent        │
   │   (standalone, no HTTP)  │         │  (id, type, createdDate, │
   └──────────────────────────┘         │   payload[])             │
                                        └─────────────────────────┘
```

- `Client` is the only class your application instantiates directly.
- `Checkouts` and `Webhooks` are accessed through `$client->checkouts()` and
  `$client->webhooks()`. They are lazily created and memoised — the same
  instance is returned across calls.
- `HttpClient` is an internal implementation detail. It is not part of the
  public API surface and may change between minor versions. You should never
  need to instantiate it directly.
- `SignatureVerifier` is independent of `Client`. It does not perform HTTP
  requests; it only validates a body + headers pair against a secret.

## Reading order

If you are new to the SDK, the reference pages are best read in this order:

1. [`Client`](client.md) — how the SDK is bootstrapped.
2. [`Checkouts`](checkouts.md) — the main happy-path operation.
3. [DTOs](dtos.md) — the request/response shapes used everywhere else.
4. [`SignatureVerifier`](signature-verifier.md) — verifying the webhook Yoco
   will send back.
5. [`Webhooks`](webhooks.md) — managing the subscriptions that trigger those
   webhooks.
6. [Exceptions](exceptions.md) — what can go wrong and how to recognise it.

For step-by-step task guides, see the top-level
[documentation index](../README.md).
