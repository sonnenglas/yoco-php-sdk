# Examples

Runnable PHP scripts that exercise every operation the SDK exposes.
Every example here is intentionally framework-free — copy them into
your own project as-is or use them as a fixture for ad-hoc debugging.

## Prerequisites

- The SDK installed in your project: `composer require sonnenglas/yoco-php-sdk`.
- A PSR-18 HTTP client; the easiest is Guzzle: `composer require guzzlehttp/guzzle`.
- A Yoco **test secret key** (`sk_test_*`) exported as `YOCO_SECRET_KEY`.
- For verifying webhooks (`03-handle-webhook.php`): a webhook secret
  (`whsec_*`) exported as `YOCO_WEBHOOK_SECRET`.
- For registering a webhook (`04-register-webhook.php`): a destination URL
  exported as `YOCO_WEBHOOK_URL` or passed as the first argument, and an
  optional `YOCO_WEBHOOK_NAME` (defaults to a generated name).
- For issuing a refund (`06-refund.php`): the checkout id of an already-paid
  checkout, passed as the first argument.

Grab keys from your Yoco developer console. Use a test key here; never paste
a live key into a shell history.

## Running an example

The scripts assume they live alongside the SDK's `vendor/` directory.
Adjust the `require __DIR__ . '/../vendor/autoload.php'` path if you have
copied them into a different project layout.

```bash
export YOCO_SECRET_KEY=sk_test_your_test_key
php examples/01-create-checkout.php
```

If you forget to export the key the script will exit with a friendly
error message instead of throwing — they all begin with a defensive env
check.

## What is in here

| Script                                                      | What it does                                                       |
|-------------------------------------------------------------|--------------------------------------------------------------------|
| [`01-create-checkout.php`](01-create-checkout.php)          | Minimal checkout: one amount, one currency, success/cancel URLs.   |
| [`02-create-checkout-with-line-items.php`](02-create-checkout-with-line-items.php) | Full checkout with line items, tax, discount, and metadata.        |
| [`03-handle-webhook.php`](03-handle-webhook.php)            | Standalone webhook receiver. Run behind `php -S` for local testing.|
| [`04-register-webhook.php`](04-register-webhook.php)        | Register (or reuse) a webhook subscription. Idempotent.            |
| [`05-list-webhooks.php`](05-list-webhooks.php)              | List every webhook subscription on your account.                   |
| [`06-refund.php`](06-refund.php)                            | Issue a full or partial refund against a checkout id.              |

## Tips

- **Verbose mode.** All scripts use `print_r` / `echo`; pipe through
  `tee` if you want to keep the output: `php examples/01-create-checkout.php | tee /tmp/yoco.log`.
- **Custom HTTP client.** None of the scripts pass a custom PSR-18 client
  — they rely on `php-http/discovery`. If you want to inject Guzzle
  manually (custom timeouts, retry middleware, proxy), see the
  [installation guide](../docs/guides/installation.md#alternative-http-clients).
- **Debug a failing call.** Yoco includes a `message` field in error
  responses; the SDK surfaces it on the exception. Run the example with
  `set -x` if you want to see exactly what was set.

## See also

- [Quickstart](../docs/guides/quickstart.md) — narrative version of
  `01-create-checkout.php`.
- [Webhook handling](../docs/guides/webhook-handling.md) — narrative
  version of `03-handle-webhook.php` and `04-register-webhook.php`.
- [Error handling](../docs/guides/error-handling.md) — the exception
  classes any of these scripts can throw.
