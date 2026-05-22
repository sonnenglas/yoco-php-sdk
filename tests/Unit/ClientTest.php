<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Client;
use Sonnenglas\Yoco\Resources\Checkouts;
use Sonnenglas\Yoco\Resources\Webhooks;

final class ClientTest extends TestCase
{
    #[Test]
    public function it_exposes_checkouts_resource(): void
    {
        $client = $this->makeClient();

        $this->assertInstanceOf(Checkouts::class, $client->checkouts());
    }

    #[Test]
    public function it_exposes_webhooks_resource(): void
    {
        $client = $this->makeClient();

        $this->assertInstanceOf(Webhooks::class, $client->webhooks());
    }

    #[Test]
    public function it_returns_same_resource_instance_on_repeated_calls(): void
    {
        $client = $this->makeClient();

        $this->assertSame($client->checkouts(), $client->checkouts());
        $this->assertSame($client->webhooks(), $client->webhooks());
    }

    #[Test]
    public function it_accepts_custom_base_uri(): void
    {
        $client = $this->makeClient(baseUri: 'https://staging.yoco.com/api');

        $this->assertSame('https://staging.yoco.com/api', $client->getBaseUri());
    }

    #[Test]
    public function it_uses_default_yoco_base_uri(): void
    {
        $client = $this->makeClient();

        $this->assertSame('https://payments.yoco.com/api', $client->getBaseUri());
    }

    private function makeClient(?string $baseUri = null): Client
    {
        $factory = new HttpFactory();

        return new Client(
            secretKey: 'sk_test_abc',
            httpClient: new MockClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUri: $baseUri,
        );
    }
}
