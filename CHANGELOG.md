# Changelog

All notable changes to `sonnenglas/yoco-php-sdk` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Versions `0.1.0`–`0.3.0` were internal pre-release iterations and were never
> published to Packagist. The first publicly available release is `1.0.0`.

## [1.0.2] - 2026-06-23

### Changed

- Package author metadata and README credits now list Przemek Peron
  (`przemek@sonnenglas.net`) instead of the generic SONNENGLAS label.

## [1.0.1] - 2026-05-22

### Fixed
- Documentation audit pass: stale `0.2.0` references replaced with `1.0.0`
  in `docs/api/client.md`, `docs/api/README.md`, `docs/guides/installation.md`,
  and `SECURITY.md`. Test count corrected in `README.md` (`98` → `114`).
- Broken external links: `developer.yoco.com/online/` → `developer.yoco.com/docs/checkout-api/`;
  removed defunct reference to `yoco/yoco-php-laravel`.
- `docs/api/exceptions.md` — corrected note: `RateLimitException::$retryAfter`
  **does** parse HTTP-date `Retry-After` (RFC 7231) as well as integer seconds.
- `docs/api/checkouts.md` and `docs/api/dtos.md` — `RefundResponse` shape
  updated to match the actual `{id, status, refundId?, message?}` Yoco
  returns.
- `examples/06-refund.php` — runtime fixed to print actual `RefundResponse`
  fields instead of non-existent `$refund->amount` / `->currency`.
- `docs/api/dtos.md` and `docs/api/README.md` — added missing entries for
  `PaymentEventPayload`, `RefundEventPayload`, `PaymentMethodDetails`,
  `CardDetails`, plus `WebhookEvent` typed-payload helpers and constants.
- `docs/api/dtos.md` — `CheckoutResponse` now documents all echoed-back
  fields (`metadata`, `successUrl`, `cancelUrl`, `failureUrl`, `lineItems`,
  `subtotalAmount`, `totalDiscount`, `totalTaxAmount`, `externalId`).
- `docs/guides/webhook-handling.md` — added `refund.succeeded` and
  `refund.failed` event types, switched the example to use typed payload
  helpers + `WebhookEvent::TYPE_*` constants.
- `docs/guides/testing.md` — added a note for the "not a valid test card"
  case (merchant-account configuration issue, not an SDK bug).
- `docs/guides/installation.md` — added a "Pin a version" section pointing
  at `^1.0`.
- `examples/README.md` — documented `YOCO_WEBHOOK_URL`, `YOCO_WEBHOOK_NAME`
  env vars + per-example arg requirements.
- `SECURITY.md` — supported versions table refreshed (`1.0.x`).
- `UPGRADING.md` — added `1.0.0` section, clarified that pre-1.0 was
  internal-only.
- `CONTRIBUTING.md` — visibility/finality guidance now matches the actual
  source tree.
- `composer.json` — added `authors`, `support` block, refined keywords and
  description.

## [1.0.0] - 2026-05-22

First stable release. The public API surface (`Client`, `Resources\Checkouts`,
`Resources\Webhooks`, `Webhook\SignatureVerifier`, all DTOs and exceptions) is
now considered stable and follows semver going forward. End-to-end verified
with a live R2.00 transaction against Yoco's production API and webhook
delivery via Standard Webhooks signature verification.

This consolidates 0.2.x and 0.3.x without API changes. If you have been using
`^0.3.0` your code continues to work unchanged.

### Highlights

- Full Yoco Online Checkout API coverage: `POST /api/checkouts`,
  `POST /api/checkouts/{id}/refund`, webhook subscriptions CRUD.
- Standard Webhooks signature verification (HMAC-SHA256, v1, key rotation
  support, replay-window guard).
- Typed DTOs for both request and response (including `PaymentEventPayload`,
  `RefundEventPayload`, `PaymentMethodDetails`, `CardDetails`).
- Granular HTTP error mapping: `400 ValidationException`,
  `403 AuthenticationException`, `409 IdempotencyConflictException`,
  `422 IdempotencyMismatchException`, `429 RateLimitException` (with
  `retryAfter`).
- `Idempotency-Key` auto-generation (UUID v4) on every `Checkouts::create` and
  `::refund` call.
- Hardened by default: 1 MiB response/webhook body caps, JSON depth 64,
  redacted webhook secret in `__debugInfo`, PSR-18 transport error wrapping
  that scrubs the `Authorization` header.
- PHPStan level 9, 114 unit tests, comprehensive `docs/`, runnable
  `examples/`.

## [0.3.0] - 2026-05-22

### Added
- **Typed webhook payload DTOs.** `WebhookEvent::asPaymentPayload()` returns a
  `PaymentEventPayload` (with `paymentMethodDetails.card` masked card + scheme)
  for `payment.*` events; `WebhookEvent::asRefundPayload()` returns a
  `RefundEventPayload` (with `failureReason` for `refund.failed`) for `refund.*`
  events. The raw `$payload` array remains available for forward compatibility.
- New DTOs: `PaymentEventPayload`, `RefundEventPayload`, `PaymentMethodDetails`,
  `CardDetails`.
- Event type constants on `WebhookEvent`: `TYPE_PAYMENT_SUCCEEDED`,
  `TYPE_PAYMENT_FAILED`, `TYPE_REFUND_SUCCEEDED`, `TYPE_REFUND_FAILED`.
- `CheckoutResponse` now parses the echoed request fields: `metadata`,
  `successUrl`, `cancelUrl`, `failureUrl`, `lineItems`, `subtotalAmount`,
  `totalDiscount`, `totalTaxAmount`, `externalId`.
- `Retry-After` HTTP-date format parsing (RFC 7231 §7.1.3) in addition to
  integer seconds. Past HTTP-date returns `0`; garbage returns `null`.

## [0.2.1] - 2026-05-22

### Fixed
- **`Checkouts::refund()` with no amount now sends an empty body** instead of
  `[]` (which Yoco rejected with `"Missing or incorrect value was provided for
  field unknown"`). Full refunds now POST with no body and no Content-Type,
  matching Yoco's expectation.
- **`RefundResponse` shape corrected** to match Yoco's OpenAPI: the response
  contains `{id, refundId, message, status}`, not `{amount, currency,
  checkoutId, paymentId}` as previously parsed. BC break for callers reading
  `$refund->amount` / `->currency` — those fields no longer exist (they are
  available in the `refund.succeeded` webhook payload instead).
- **Error message extraction** now reads `description` (used by Yoco for refund
  failures and idempotency errors), `error.message`, and `errors[0].message`
  in addition to `message`. Previously `ApiException::getMessage()` returned
  the generic `"HTTP 400 from Yoco API"` for refund declines such as
  `"This transaction cannot be refunded as the card used does not support
  refunds"`.

### Changed
- `HttpClient::post()` signature: `array $body` → `?array $body`. POST with
  `null` body sends no payload and no Content-Type. Direct callers of
  `HttpClient` need to adjust if they were passing `[]` for "no body".

## [0.2.0] - 2026-05-21

### Added
- `Sonnenglas\Yoco\Exceptions\IdempotencyConflictException` for HTTP 409.
- `Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException` for HTTP 422.
- `Sonnenglas\Yoco\Dto\RefundResponse` and `Resources\Checkouts::refund()` for
  `POST /api/checkouts/{id}/refund` (full and partial refunds).
- `Idempotency-Key` header support on `Checkouts::create()` and `::refund()`.
  Auto-generates a UUID v4 if no key is supplied.
- `CheckoutResponse` now parses `paymentId`, `processingMode`, `merchantId`
  and `clientReferenceId`.
- `WebhookSubscription` now exposes `mode` (`live`/`test`).
- `WebhookSubscription::__debugInfo()` redacts the `secret` field for safer
  logging.
- `RateLimitException::$retryAfter` populated from the `Retry-After` header.
- Optional clock callable in `SignatureVerifier` for deterministic tests and
  for verifying historical events.
- User-Agent header `sonnenglas-yoco-php-sdk/<version> (PHP/<runtime>)`.
- Maximum response body size (`HttpClient::MAX_RESPONSE_BYTES = 1 MiB`) and
  maximum webhook body size (`SignatureVerifier::MAX_BODY_BYTES = 1 MiB`).
- `tests/Fixtures/` with realistic Yoco payload samples + `FixtureLoader`.

### Changed
- **BREAKING:** HTTP status mapping reworked to reflect the Yoco Checkout API:
  - `400` → `ValidationException` (was caught by generic `ApiException`)
  - `403` → `AuthenticationException` (Yoco uses 403 for missing/invalid keys,
    not 401)
  - `409` → `IdempotencyConflictException` (new)
  - `422` → `IdempotencyMismatchException` (NOT `ValidationException` — in
    the Checkout API 422 means the body differs from the original request
    stored under the same Idempotency-Key)
  - `401` and `429` mappings remain as defensive fallbacks.
- `SignatureVerifier::verify()` now validates `toleranceSeconds` is between
  `0` and `MAX_TOLERANCE_SECONDS` (3600). Out-of-range values throw
  `\InvalidArgumentException`.
- `Webhooks::list()` now throws `ApiException` when the `subscriptions` key is
  missing, instead of silently returning an empty list.
- PSR-18 transport errors are now wrapped without leaking the underlying
  exception message (which can contain `Authorization: Bearer <secret>` from
  some clients). The original exception is preserved via `getPrevious()`.
- JSON depth limit reduced from 512 to 64 in both `HttpClient::parseJson()`
  and `SignatureVerifier::parseEvent()`.

### Fixed
- `assertSignatureMatches()` now distinguishes "header had only unsupported
  schemes" from "header had v1 but none matched", producing clearer error
  messages.

## [0.1.0] - 2026-05-20

### Added
- Initial release: `Client`, `Http\HttpClient`, `Resources\Checkouts`,
  `Resources\Webhooks`, `Webhook\SignatureVerifier`, DTOs and Exception
  hierarchy.
- PHPStan level 9 + PHPUnit 10 test suite.
