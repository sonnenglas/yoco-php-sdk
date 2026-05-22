<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sonnenglas\Yoco\Http\HttpClient;
use Sonnenglas\Yoco\Resources\Checkouts;
use Sonnenglas\Yoco\Resources\Webhooks;

class Client
{
    public const DEFAULT_BASE_URI = 'https://payments.yoco.com/api';

    public const VERSION = '1.0.0';

    private readonly HttpClient $http;

    private readonly string $baseUri;

    private ?Checkouts $checkouts = null;

    private ?Webhooks $webhooks = null;

    public function __construct(
        string $secretKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $baseUri = null,
    ) {
        $this->baseUri = $baseUri ?? self::DEFAULT_BASE_URI;

        $this->http = new HttpClient(
            secretKey: $secretKey,
            baseUri: $this->baseUri,
            httpClient: $httpClient ?? Psr18ClientDiscovery::find(),
            requestFactory: $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            userAgent: self::buildUserAgent(),
        );
    }

    private static function buildUserAgent(): string
    {
        return sprintf('sonnenglas-yoco-php-sdk/%s (PHP/%s)', self::VERSION, PHP_VERSION);
    }

    public function checkouts(): Checkouts
    {
        return $this->checkouts ??= new Checkouts($this->http);
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks($this->http);
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }
}
