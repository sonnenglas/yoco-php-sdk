<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Resources;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\WebhookSubscription;
use Sonnenglas\Yoco\Http\HttpClient;
use Sonnenglas\Yoco\Resources\Webhooks;
use Sonnenglas\Yoco\Tests\Fixtures\FixtureLoader;

final class WebhooksTest extends TestCase
{
    private MockClient $mock;

    private Webhooks $webhooks;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $factory = new HttpFactory();
        $http = new HttpClient(
            secretKey: 'sk_test_abc',
            baseUri: 'https://payments.yoco.com/api',
            httpClient: $this->mock,
            requestFactory: $factory,
            streamFactory: $factory,
        );
        $this->webhooks = new Webhooks($http);
    }

    #[Test]
    public function it_creates_a_webhook_and_returns_secret_once(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'wh_123',
            'name' => 'prod',
            'url' => 'https://example.com/hooks/yoco',
            'mode' => 'live',
            'secret' => 'whsec_AbCd123',
        ], JSON_THROW_ON_ERROR)));

        $subscription = $this->webhooks->create('prod', 'https://example.com/hooks/yoco');

        $request = $this->mock->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/webhooks', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(['name' => 'prod', 'url' => 'https://example.com/hooks/yoco'], $body);

        $this->assertInstanceOf(WebhookSubscription::class, $subscription);
        $this->assertSame('wh_123', $subscription->id);
        $this->assertSame('prod', $subscription->name);
        $this->assertSame('https://example.com/hooks/yoco', $subscription->url);
        $this->assertSame('whsec_AbCd123', $subscription->secret);
    }

    #[Test]
    public function it_lists_webhooks_without_secrets(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'subscriptions' => [
                ['id' => 'wh_1', 'name' => 'a', 'url' => 'https://a.example/hooks', 'mode' => 'live'],
                ['id' => 'wh_2', 'name' => 'b', 'url' => 'https://b.example/hooks', 'mode' => 'test'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $list = $this->webhooks->list();

        $request = $this->mock->getRequests()[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringEndsWith('/webhooks', (string) $request->getUri());

        $this->assertCount(2, $list);
        $this->assertSame('wh_1', $list[0]->id);
        $this->assertNull($list[0]->secret);
        $this->assertSame('wh_2', $list[1]->id);
    }

    #[Test]
    public function it_parses_realistic_subscriptions_list_from_fixture(): void
    {
        $this->mock->addResponse(new Response(200, [], FixtureLoader::raw('webhook-list')));

        $list = $this->webhooks->list();

        $this->assertCount(2, $list);
        $this->assertSame('production', $list[0]->name);
        $this->assertSame('live', $list[0]->mode);
        $this->assertSame('test', $list[1]->mode);
    }

    #[Test]
    public function it_parses_webhook_created_response_from_fixture(): void
    {
        $this->mock->addResponse(new Response(200, [], FixtureLoader::raw('webhook-created')));

        $created = $this->webhooks->create('production', 'https://example.com/hooks/yoco');

        $this->assertSame('wh_4kFqUJtnzcEevTaWCB1uhsGm', $created->id);
        $this->assertSame('live', $created->mode);
        $this->assertSame('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $created->secret);
    }

    #[Test]
    public function it_returns_empty_list_when_no_subscriptions(): void
    {
        $this->mock->addResponse(new Response(200, [], '{"subscriptions":[]}'));

        $list = $this->webhooks->list();

        $this->assertSame([], $list);
    }

    #[Test]
    public function it_throws_when_subscriptions_key_is_missing(): void
    {
        // Yoco might one day paginate as {data:{subscriptions:[...]}} or rename
        // the wrapping key. We MUST fail loudly to avoid silently registering
        // duplicate webhooks via `RegisterYocoWebhookCommand`.
        $this->mock->addResponse(new Response(200, [], '{"data":{"items":[]}}'));

        $this->expectException(\Sonnenglas\Yoco\Exceptions\ApiException::class);
        $this->expectExceptionMessage('subscriptions');
        $this->webhooks->list();
    }

    #[Test]
    public function it_throws_when_subscriptions_value_is_not_an_array(): void
    {
        $this->mock->addResponse(new Response(200, [], '{"subscriptions":"unexpected"}'));

        $this->expectException(\Sonnenglas\Yoco\Exceptions\ApiException::class);
        $this->webhooks->list();
    }

    #[Test]
    public function it_throws_when_a_subscription_entry_lacks_required_fields(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'subscriptions' => [
                ['id' => 'wh_1', 'name' => 'a', 'url' => 'https://a.example/hooks', 'mode' => 'live'],
                ['id' => 'wh_2'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(\Sonnenglas\Yoco\Exceptions\ApiException::class);
        $this->webhooks->list();
    }

    #[Test]
    public function it_url_encodes_webhook_id_in_delete(): void
    {
        $this->mock->addResponse(new Response(204));

        $this->webhooks->delete('wh/foo bar');

        $request = $this->mock->getRequests()[0];
        $this->assertStringEndsWith('/webhooks/wh%2Ffoo%20bar', (string) $request->getUri());
    }

    #[Test]
    public function it_deletes_a_webhook_by_id(): void
    {
        $this->mock->addResponse(new Response(204));

        $this->webhooks->delete('wh_123');

        $request = $this->mock->getRequests()[0];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/webhooks/wh_123', (string) $request->getUri());
    }
}
