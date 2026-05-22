# Sonnenglas\Yoco\Client

> Top-level entry point. Holds the API secret and PSR-18 / PSR-17 dependencies, and exposes lazy accessors for the API resources.

```php
namespace Sonnenglas\Yoco;

class Client
```

The `Client` is the single object your application should construct. Once
created it is effectively stateless — it can safely be reused for the
lifetime of the process and stored as a singleton in a DI container.

## Constants

| Constant | Type | Value | Description |
|----------|------|-------|-------------|
| `Client::DEFAULT_BASE_URI` | `string` | `'https://payments.yoco.com/api'` | The production Yoco Online Checkout API base URI. Used when no `$baseUri` is supplied. |
| `Client::VERSION` | `string` | `'1.0.0'` | Current SDK version. Embedded in the `User-Agent` header sent on every request. |

## Constructor

### `__construct(...)`

> Build a `Client` bound to a Yoco secret key and optional PSR HTTP dependencies.

**Signature:**

```php
public function __construct(
    string $secretKey,
    ?\Psr\Http\Client\ClientInterface $httpClient = null,
    ?\Psr\Http\Message\RequestFactoryInterface $requestFactory = null,
    ?\Psr\Http\Message\StreamFactoryInterface $streamFactory = null,
    ?string $baseUri = null,
)
```

**Parameters:**

| Name | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `$secretKey` | `string` | yes | — | Your Yoco API secret. Use `sk_test_*` for sandbox calls and `sk_live_*` for production. Passed as `Authorization: Bearer <key>` on every request. |
| `$httpClient` | `?Psr\Http\Client\ClientInterface` | no | `Psr18ClientDiscovery::find()` | Any PSR-18 client. When `null`, `php-http/discovery` finds an installed client (Guzzle 7 is the recommended one and the `composer.json` suggests it). |
| `$requestFactory` | `?Psr\Http\Message\RequestFactoryInterface` | no | `Psr17FactoryDiscovery::findRequestFactory()` | PSR-17 request factory. Defaults to a discovered factory when `null`. |
| `$streamFactory` | `?Psr\Http\Message\StreamFactoryInterface` | no | `Psr17FactoryDiscovery::findStreamFactory()` | PSR-17 stream factory. Defaults to a discovered factory when `null`. |
| `$baseUri` | `?string` | no | `Client::DEFAULT_BASE_URI` | Override the API base URI. Trailing slashes are normalised. |

**Throws:**

- Constructor itself throws nothing directly, but PSR discovery will throw
  `Http\Discovery\Exception\NotFoundException` at construction time if no
  PSR-18 client or PSR-17 factory is installed and you did not inject one.

**Example — minimal:**

```php
use Sonnenglas\Yoco\Client;

$client = new Client(secretKey: getenv('YOCO_SECRET_KEY'));
```

**Example — fully injected:**

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Sonnenglas\Yoco\Client;

$factory = new HttpFactory();

$client = new Client(
    secretKey: getenv('YOCO_SECRET_KEY'),
    httpClient: new Guzzle(['timeout' => 10.0]),
    requestFactory: $factory,
    streamFactory: $factory,
    baseUri: 'https://payments.yoco.com/api',
);
```

## Methods

### `checkouts()`

> Return the lazily-instantiated `Checkouts` resource.

**Signature:**

```php
public function checkouts(): \Sonnenglas\Yoco\Resources\Checkouts
```

**Returns:** `Resources\Checkouts` — the same instance on every subsequent call.

**Example:**

```php
$response = $client->checkouts()->create($request);
```

### `webhooks()`

> Return the lazily-instantiated `Webhooks` resource.

**Signature:**

```php
public function webhooks(): \Sonnenglas\Yoco\Resources\Webhooks
```

**Returns:** `Resources\Webhooks` — the same instance on every subsequent call.

**Example:**

```php
$subscription = $client->webhooks()->create(
    name: 'production',
    url:  'https://example.com/hooks/yoco',
);
```

### `getBaseUri()`

> Return the base URI this client is configured for.

**Signature:**

```php
public function getBaseUri(): string
```

**Returns:** `string` — the (trailing-slash-trimmed) base URI passed in the constructor, or `Client::DEFAULT_BASE_URI` if none was supplied.

**Example:**

```php
$client = new Client(secretKey: 'sk_test_abc');

echo $client->getBaseUri();              // 'https://payments.yoco.com/api'
```

---

## Configuring an HTTP client

The SDK is PSR-18 compliant and will work with any conforming client. By
default it discovers one through `php-http/discovery`. Guzzle 7 is the
recommended client (and is listed in `composer.json` as a `suggest`).

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Sonnenglas\Yoco\Client;

$factory = new HttpFactory();

$client = new Client(
    secretKey: getenv('YOCO_SECRET_KEY'),
    httpClient: new Guzzle([
        'timeout'         => 10.0,
        'connect_timeout' => 3.0,
        // Add a retry middleware here if your platform doesn't have one.
    ]),
    requestFactory: $factory,
    streamFactory:  $factory,
);
```

`Psr18ClientDiscovery::find()` is only a fallback for the common case where
exactly one PSR-18 client is installed and no advanced configuration is
needed. As soon as you need timeouts, proxies, middleware, or instrumentation,
inject the client yourself.

## Custom base URI

```php
$client = new Client(
    secretKey: 'sk_test_abc',
    baseUri:   'https://staging.yoco.com/api',
);
```

Override `$baseUri` if Yoco ever publishes a separate sandbox host, or if
you proxy production traffic through an internal gateway. The default value
`https://payments.yoco.com/api` is correct for both `sk_test_*` and
`sk_live_*` secrets — the API distinguishes test from live by the key, not
by the host.

## Thread-safety and instantiation pattern

`Client` is stateless apart from lazily caching the `Checkouts` and
`Webhooks` resource instances inside private properties. PHP is single-threaded
per-request, so this caching is safe. Treat `Client` as a long-lived
dependency:

- **Laravel:** bind as a singleton in a service provider.
- **Symfony:** declare as an autowired service (default shared lifetime).
- **Plain PHP:** construct once at the top of the request and pass it down.

Constructing a new `Client` on every call is wasteful but not unsafe — the
underlying PSR-18 client and PSR-17 factories are themselves expected to be
shareable.

## See also

- [`Resources\Checkouts`](checkouts.md) — operations on hosted checkouts.
- [`Resources\Webhooks`](webhooks.md) — manage webhook subscriptions.
- [Installation guide](../guides/installation.md) — Composer + framework
  bootstrap details.
