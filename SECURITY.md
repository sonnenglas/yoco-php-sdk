# Security Policy

`sonnenglas/yoco-php-sdk` sits on the critical path for payment processing. We
take security reports seriously and aim to resolve confirmed vulnerabilities
promptly.

## Supported versions

| Version  | Supported          |
|----------|--------------------|
| `1.0.x`  | Yes                |
| `< 1.0`  | No — please upgrade |

Only the latest minor release line on the current major receives security
fixes. We recommend pinning to `^1.0` and tracking releases.

## Reporting a vulnerability

**Please do not report security issues through public GitHub issues, pull
requests, or discussions.**

There are two private channels for reporting vulnerabilities:

1. **GitHub Security Advisories** (preferred). Open a private advisory at
   <https://github.com/sonnenglas/yoco-php-sdk/security/advisories/new>. This
   gives us a private fork to coordinate the fix, draft a CVE, and credit you.
2. **Email** to <security@sonnenglas.net>. Encrypt with PGP if you can; if you
   need a key, request it in your initial email.

When reporting, please include:

- A description of the issue and its impact.
- Steps to reproduce, including SDK version and PHP version.
- A proof-of-concept where feasible.
- Your suggested remediation, if you have one.

## Response time

We aim to:

- **Acknowledge** your report within **3 business days**.
- **Confirm or dismiss** the issue within **7 business days**.
- **Release a fix** for confirmed high-severity issues as quickly as possible —
  typically within 14 days, faster for actively exploited issues.

These are best-effort targets and not contractual guarantees.

## Coordinated disclosure

We follow coordinated disclosure. After a fix is released, we will publish a
GitHub Security Advisory describing the issue, affected versions, and the
fixed release. Reporters who wish to be credited will be named in the
advisory.

Please do not publicly disclose the vulnerability until the advisory is
published.

## What this SDK protects against

The SDK ships with a number of defensive measures. Understanding them helps
both when integrating and when reviewing reports.

### Webhook signature verification

- **Constant-time comparison.** Signatures are compared with `hash_equals`
  rather than `===` to prevent timing side-channel attacks. See
  [`SignatureVerifier::assertSignatureMatches`](src/Webhook/SignatureVerifier.php).
- **Replay protection.** The `webhook-timestamp` header is enforced against a
  configurable tolerance window (default 180 s, maximum 3600 s). Out-of-range
  tolerance values raise `InvalidArgumentException` rather than silently
  weakening the check.
- **Multi-signature parsing.** The Standard Webhooks `v1` scheme allows
  multiple signatures separated by spaces (used during key rotation). The
  verifier checks every `v1`-prefixed entry; non-`v1` entries (legacy `v0,`,
  future `v2,`, unrelated schemes) are skipped without failing the request, so
  callers stay forward-compatible with newer signature versions.

### Transport-error sanitization

PSR-18 client implementations sometimes embed the full outgoing request —
including the `Authorization: Bearer <secret>` header — in their exception
messages. `HttpClient` wraps every `ClientExceptionInterface` it sees and
**discards the inner message** when constructing the public `ApiException`.
The original exception is still attached via `getPrevious()`, so callers that
explicitly want to inspect it can, but no log line written by accident will
leak the API secret.

### Webhook secret redaction

`WebhookSubscription::__debugInfo()` returns `***redacted***` in place of the
`secret` field. This means `var_dump($subscription)`, `print_r($subscription)`,
and Symfony VarDumper output never accidentally print the webhook secret.

### Defensive payload limits

| Limit                       | Value      | Defined in                                              |
|-----------------------------|------------|---------------------------------------------------------|
| Response body max size      | 1 MiB      | `Sonnenglas\Yoco\Http\HttpClient::MAX_RESPONSE_BYTES`   |
| Webhook body max size       | 1 MiB      | `Sonnenglas\Yoco\Webhook\SignatureVerifier::MAX_BODY_BYTES` |
| JSON decode depth           | 64         | both `HttpClient` and `SignatureVerifier`               |
| Webhook tolerance (max)     | 3600 s     | `SignatureVerifier::MAX_TOLERANCE_SECONDS`              |
| Minimum checkout amount     | 200 cents  | `CreateCheckoutRequest::MIN_AMOUNT_CENTS`               |

Anything exceeding these limits fails fast with a clear exception rather than
consuming unbounded memory.

### Idempotency-Key auto-generation

`Checkouts::create()` and `Checkouts::refund()` generate a cryptographically
random RFC 4122 v4 UUID (`random_bytes(16)`) for the `Idempotency-Key` header
when the caller does not supply one. This means accidental double-clicks or
network-level retries within a single process never duplicate charges —
provided the same call site is hit.

## Out of scope

The following are **not** considered vulnerabilities for the purposes of this
policy:

- Findings against unsupported versions (see the table above).
- Issues in Yoco's API itself — please report those to Yoco directly.
- Vulnerabilities in transitive dependencies (Guzzle, php-http/discovery, etc.)
  unless they are exploitable specifically because of how this SDK uses them.
- Theoretical timing attacks that require many orders of magnitude more
  samples than the webhook tolerance window permits.
