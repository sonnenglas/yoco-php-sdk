# Contributing to yoco-php-sdk

Thanks for taking the time to contribute. This document describes how the
codebase is organised, the standards we hold ourselves to, and the workflow we
use to ship changes.

## Code of conduct

Be respectful, be patient, and assume good intent. We aim for a welcoming and
professional environment for contributors of all backgrounds and experience
levels. Disagreements about technical direction are healthy — personal attacks
are not.

## Setting up a development environment

```bash
git clone https://github.com/sonnenglas/yoco-php-sdk.git
cd yoco-php-sdk
composer install
composer check          # PHPStan level 9 + full test suite
```

A green `composer check` is the bar every PR must clear locally before opening
a review.

## Branching

- Cut feature branches from `master`.
- Use descriptive branch names: `feat/refund-partial-amount`, `fix/webhook-replay-window`,
  `docs/contributing-guide`.
- Keep branches small and focused — one concept per PR. Multi-concern PRs will
  be asked to split.

## Coding standards

This SDK is held to a stricter standard than the average library because it
sits on the critical path for payment flows.

- **PHPStan level 9** — `composer phpstan` must pass with zero errors. Use
  `@phpstan-ignore-line` only with an accompanying explanatory comment.
- **All tests must pass** — `composer test`. New behaviour requires new tests.
- **PHP-CS-Fixer** — run `composer cs-fix` before committing.
- **`readonly` for DTOs.** Every DTO under `src/Dto/` is `final readonly` —
  follow the pattern.
- **`final` where extension is not part of the contract.** DTOs, exceptions,
  `HttpClient`, and `SignatureVerifier` are `final`. `Client`, `Resources\*`,
  and `BaseResource` are intentionally **not** `final` so consumers can mock
  them in tests; `YocoException` is `abstract`.
- **`strict_types=1`** at the top of every file.
- **Explicit return types** on every method and function (PHPStan level 9
  enforces this; PHP-CS-Fixer will fail the build if it slips through).
- **Constructor property promotion** for all DTOs and most services.
- **`private` for helpers** that are clearly internal (e.g. JSON parsing
  utilities inside `HttpClient`). Prefer `protected` only where you genuinely
  want subclasses to override behaviour.

## Test-first workflow (TDD)

The codebase was built test-first and we keep it that way:

1. **Write the failing test.** Cover the new behaviour, then run
   `composer test` and watch it fail.
2. **Implement the minimum to make it pass.** Resist the temptation to
   refactor while red.
3. **Refactor on green.** Clean up the implementation with the test as a safety
   net.
4. **Run `composer check`.** Both PHPStan level 9 and the full test suite must
   stay green.

For bugfixes, the failing test that demonstrates the bug should be the **first
commit** in your PR. This keeps the history honest about what was broken.

## Commit messages

Use the imperative mood — "Add refund support", not "Added refund support" or
"Adds refund support". Keep the subject line under 72 characters; wrap the body
at 80 columns.

Reference issues at the end of the subject when applicable:

```
Add Idempotency-Key auto-generation to refund (#42)

Refunds now generate a fresh UUID v4 Idempotency-Key by default, mirroring
the behaviour of Checkouts::create(). Callers can still pass an explicit key
for deterministic retries.
```

## Pull request process

1. Open a PR against `master`. Fill in the PR template.
2. Ensure CI is green — PHPStan level 9, PHPUnit, PHP-CS-Fixer.
3. Update [`CHANGELOG.md`](CHANGELOG.md) if the change is user-facing.
4. Update [`UPGRADING.md`](UPGRADING.md) if the change is breaking.
5. Update relevant docs under `docs/` if you changed observable behaviour.
6. A maintainer will review. Expect at least one round of feedback on
   non-trivial changes.

Small, focused PRs are reviewed and merged faster than large ones. If a PR
grows beyond ~400 lines of diff (excluding tests and fixtures), consider
splitting it.

## Reporting bugs

Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) on GitHub.
Include:

- PHP version (`php -v`)
- SDK version (`composer show sonnenglas/yoco-php-sdk`)
- PSR-18 client and version
- A minimal reproduction (the smaller the better)
- The full stack trace, with API keys and webhook secrets redacted

Security vulnerabilities should **not** be reported as public issues — see
[SECURITY.md](SECURITY.md) for the responsible disclosure process.

## Proposing features

Use the [feature request template](.github/ISSUE_TEMPLATE/feature_request.md).
Include:

- The use case driving the request
- A sketch of the API you would like to call
- A link to the relevant [Yoco API endpoint](https://developer.yoco.com/docs/checkout-api/)
  if the feature wraps a Yoco capability

Features that are outside the [supported scope](README.md#supported-features)
will be considered case-by-case. We are conservative about expanding the
surface area — every endpoint added is one we commit to maintaining.

## License

By contributing, you agree that your contributions will be licensed under the
[MIT License](LICENSE).
