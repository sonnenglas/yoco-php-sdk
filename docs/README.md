# sonnenglas/yoco-php-sdk Documentation

Framework-agnostic PHP SDK for the Yoco Online (Checkout) Payment API and
Standard Webhooks signature verification. This documentation covers everything
from a five-minute quickstart through full API reference for every public
class, method, DTO, and exception in the SDK.

---

## Getting Started

- [Installation](guides/installation.md) — Composer requirements, PSR-18
  client choice, Laravel / Symfony / plain PHP setup.
- [Quickstart](guides/quickstart.md) — Create a checkout, redirect the
  customer, verify the webhook, ship.

## Guides

End-to-end walkthroughs of common workflows. Each guide is self-contained
and can be read independently.

- [Webhook handling](guides/webhook-handling.md) — Full webhook lifecycle:
  subscription registration, secret storage, replay protection, idempotent
  event processing.
- [Signature verification](guides/signature-verification.md) — How the
  Standard Webhooks HMAC-SHA256 scheme works in practice, how to debug a
  failing signature, secret rotation strategy.
- [Testing](guides/testing.md) — Mocking the SDK with `php-http/mock-client`,
  driving the verifier with a fixed clock, fixture-based event replay.
- [Error handling](guides/error-handling.md) — Exception hierarchy in
  practice, retry strategies for idempotency conflicts, rate-limit backoff.
- [Laravel integration](guides/laravel-integration.md) — Service container
  bindings, queued webhook processing, controller patterns.
- [Symfony integration](guides/symfony-integration.md) — Service definitions,
  controller patterns, queued message handlers.
- [Plain PHP](guides/plain-php.md) — Standalone receiver script for users not
  on a framework.

## API Reference

Authoritative reference for every public symbol. Generated from the SDK
source — every documented signature matches the code 1:1.

- [Overview](api/README.md) — Class index and architecture diagram.
- [`Sonnenglas\Yoco\Client`](api/client.md) — Top-level entry point.
- [`Sonnenglas\Yoco\Resources\Checkouts`](api/checkouts.md) — Create and
  refund hosted checkouts.
- [`Sonnenglas\Yoco\Resources\Webhooks`](api/webhooks.md) — Manage webhook
  subscriptions.
- [`Sonnenglas\Yoco\Webhook\SignatureVerifier`](api/signature-verifier.md) —
  Verify inbound webhook requests.
- [DTOs](api/dtos.md) — Request and response data objects.
- [Exceptions](api/exceptions.md) — Exception hierarchy and HTTP mapping.

## Examples

Runnable code samples live under [`examples/`](../examples/) in the
repository root, including end-to-end webhook receivers, refund flows, and
framework-specific stubs.

## Resources

- [Yoco developer documentation](https://developer.yoco.com/) — Official
  API reference for the underlying Online Checkout HTTP API.
- [Yoco merchant support](https://support.yoco.com/) — Account, sandbox
  credentials, dashboard help.
- [GitHub issues](https://github.com/sonnenglas/yoco-php-sdk/issues) — Bug
  reports and feature requests for this SDK.
- [Standard Webhooks specification](https://www.standardwebhooks.com/) —
  The HMAC-SHA256 scheme Yoco follows; the verifier in this SDK is a
  compliant implementation.

---

## What's covered

This SDK targets the **Yoco Online Checkout API** at
`https://payments.yoco.com/api`. That includes:

- `POST /checkouts` — Create a hosted payment session.
- `POST /checkouts/{id}/refund` — Full or partial refunds.
- `POST /webhooks`, `GET /webhooks`, `DELETE /webhooks/{id}` — Webhook
  subscription management.
- Inbound webhook signature verification (Standard Webhooks v1).

## What's not covered

The SDK does **not** wrap the main Yoco Payments API at
`https://online.yoco.com/v1/` (for example `/v1/charges`, in-person SDKs, or
the merchant-facing dashboard APIs). If you need those endpoints, call them
directly or contact Yoco support.

The SDK also does not include retry, backoff, or persistence policies — by
design. Those decisions belong to your application; see the
[error handling guide](guides/error-handling.md) for recommended patterns.
