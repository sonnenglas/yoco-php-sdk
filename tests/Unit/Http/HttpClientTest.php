<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Sonnenglas\Yoco\Exceptions\ApiException;
use Sonnenglas\Yoco\Exceptions\AuthenticationException;
use Sonnenglas\Yoco\Exceptions\IdempotencyConflictException;
use Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException;
use Sonnenglas\Yoco\Exceptions\RateLimitException;
use Sonnenglas\Yoco\Exceptions\ValidationException;
use Sonnenglas\Yoco\Http\HttpClient;

final class HttpClientTest extends TestCase
{
    private MockClient $mockHttp;

    private HttpClient $httpClient;

    protected function setUp(): void
    {
        $this->mockHttp = new MockClient();
        $factory = new HttpFactory();
        $this->httpClient = new HttpClient(
            secretKey: 'sk_test_abc',
            baseUri: 'https://payments.yoco.com/api',
            httpClient: $this->mockHttp,
            requestFactory: $factory,
            streamFactory: $factory,
            userAgent: 'sonnenglas-yoco-php-sdk/test',
        );
    }

    #[Test]
    public function it_sends_user_agent_header(): void
    {
        $this->mockHttp->addResponse(new Response(200, [], '{}'));

        $this->httpClient->post('/checkouts', []);

        $request = $this->lastRequest();
        $this->assertSame('sonnenglas-yoco-php-sdk/test', $request->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function it_sends_post_with_bearer_token_and_json_body(): void
    {
        $this->mockHttp->addResponse(new Response(200, [], '{"id":"ch_123"}'));

        $result = $this->httpClient->post('/checkouts', ['amount' => 1000]);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://payments.yoco.com/api/checkouts', (string) $request->getUri());
        $this->assertSame('Bearer sk_test_abc', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('{"amount":1000}', (string) $request->getBody());
        $this->assertSame(['id' => 'ch_123'], $result);
    }

    #[Test]
    public function it_sends_get_with_bearer_token(): void
    {
        $this->mockHttp->addResponse(new Response(200, [], '{"data":[]}'));

        $result = $this->httpClient->get('/webhooks');

        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://payments.yoco.com/api/webhooks', (string) $request->getUri());
        $this->assertSame('Bearer sk_test_abc', $request->getHeaderLine('Authorization'));
        $this->assertSame(['data' => []], $result);
    }

    #[Test]
    public function it_sends_delete_and_returns_empty_array_for_204(): void
    {
        $this->mockHttp->addResponse(new Response(204));

        $result = $this->httpClient->delete('/webhooks/wh_1');

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame([], $result);
    }

    #[Test]
    public function it_throws_validation_exception_on_400(): void
    {
        $this->mockHttp->addResponse(new Response(400, [], '{"message":"amount must be at least 200"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('amount must be at least 200', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_authentication_exception_on_401(): void
    {
        $this->mockHttp->addResponse(new Response(401, [], '{"message":"Invalid API key"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('Invalid API key', $e->getMessage());
            $this->assertSame(['message' => 'Invalid API key'], $e->responseBody);
        }
    }

    #[Test]
    public function it_throws_authentication_exception_on_403(): void
    {
        $this->mockHttp->addResponse(new Response(403, [], '{"message":"A key is required, but has not been specified."}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(403, $e->statusCode);
        }
    }

    #[Test]
    public function it_throws_idempotency_conflict_exception_on_409(): void
    {
        $this->mockHttp->addResponse(new Response(409, [], '{"message":"A request with this Idempotency-Key is already being processed."}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame(409, $e->statusCode);
        }
    }

    #[Test]
    public function it_throws_idempotency_mismatch_exception_on_422(): void
    {
        $this->mockHttp->addResponse(new Response(422, [], '{"message":"The request payload does not match the original request."}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected IdempotencyMismatchException');
        } catch (IdempotencyMismatchException $e) {
            $this->assertSame(422, $e->statusCode);
        }
    }

    #[Test]
    public function it_throws_rate_limit_exception_on_429(): void
    {
        $this->mockHttp->addResponse(new Response(429, [], '{"message":"Too many requests"}'));

        $this->expectException(RateLimitException::class);
        $this->httpClient->post('/checkouts', []);
    }

    #[Test]
    public function it_exposes_retry_after_seconds_on_429(): void
    {
        $this->mockHttp->addResponse(new Response(429, ['Retry-After' => '42'], '{"message":"Too many requests"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(42, $e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_when_header_is_missing(): void
    {
        $this->mockHttp->addResponse(new Response(429, [], '{"message":"slow down"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_parses_http_date_retry_after(): void
    {
        $future = gmdate('D, d M Y H:i:s', time() + 30).' GMT';
        $this->mockHttp->addResponse(new Response(429, ['Retry-After' => $future], '{"message":"x"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            // 30s ± 2s tolerance (test execution time)
            $this->assertNotNull($e->retryAfter);
            $this->assertGreaterThanOrEqual(28, $e->retryAfter);
            $this->assertLessThanOrEqual(32, $e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_zero_for_past_http_date_retry_after(): void
    {
        $past = gmdate('D, d M Y H:i:s', time() - 60).' GMT';
        $this->mockHttp->addResponse(new Response(429, ['Retry-After' => $past], '{"message":"x"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(0, $e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_for_garbage_header(): void
    {
        $this->mockHttp->addResponse(new Response(429, ['Retry-After' => 'not a date'], '{"message":"x"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_rejects_response_body_larger_than_limit(): void
    {
        $huge = '{"data":"'.str_repeat('a', 1024 * 1024 + 1).'"}';
        $this->mockHttp->addResponse(new Response(200, [], $huge));

        $this->expectException(\Sonnenglas\Yoco\Exceptions\ApiException::class);
        $this->expectExceptionMessage('Response body exceeds the maximum allowed size');
        $this->httpClient->post('/checkouts', []);
    }

    #[Test]
    public function it_throws_api_exception_on_other_4xx(): void
    {
        $this->mockHttp->addResponse(new Response(404, [], '{"message":"Not found"}'));

        try {
            $this->httpClient->get('/checkouts/missing');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('Not found', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_api_exception_on_5xx(): void
    {
        $this->mockHttp->addResponse(new Response(500, [], '{"message":"Internal error"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->statusCode);
        }
    }

    #[Test]
    public function it_throws_api_exception_when_response_is_not_valid_json(): void
    {
        $this->mockHttp->addResponse(new Response(200, [], 'not-json'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from Yoco API');
        $this->httpClient->post('/checkouts', []);
    }

    #[Test]
    public function it_wraps_psr18_network_error_in_api_exception(): void
    {
        $this->mockHttp->addException(new \GuzzleHttp\Exception\ConnectException(
            'Connection refused',
            new \GuzzleHttp\Psr7\Request('POST', 'https://payments.yoco.com/api/checkouts'),
        ));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP transport error');
        $this->httpClient->post('/checkouts', []);
    }

    #[Test]
    public function it_does_not_leak_psr18_message_into_exception_message(): void
    {
        // Some PSR-18 client errors embed the full request — including the
        // Authorization header — in their getMessage(). The SDK must NOT
        // propagate that into ApiException::getMessage().
        $leakyException = new \GuzzleHttp\Exception\ConnectException(
            'Connection refused: Authorization: Bearer sk_test_super_secret_key',
            new \GuzzleHttp\Psr7\Request('POST', 'https://payments.yoco.com/api/checkouts'),
        );
        $this->mockHttp->addException($leakyException);

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('sk_test_super_secret_key', $e->getMessage());
            $this->assertStringNotContainsString('Authorization', $e->getMessage());
            // Original is available via previous() if the caller really needs it.
            $this->assertSame($leakyException, $e->getPrevious());
        }
    }

    #[Test]
    public function it_uses_fallback_message_when_response_lacks_message_field(): void
    {
        $this->mockHttp->addResponse(new Response(400, [], '{"errorCode":"X"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('HTTP 400 from Yoco API', $e->getMessage());
            $this->assertSame(['errorCode' => 'X'], $e->responseBody);
        }
    }

    #[Test]
    public function it_extracts_message_from_description_field_used_by_yoco_refunds(): void
    {
        // Real Yoco refund-decline body observed in a live test.
        $this->mockHttp->addResponse(new Response(400, [], '{"description":"This transaction cannot be refunded as the card used does not support refunds"}'));

        try {
            $this->httpClient->post('/checkouts/ch_x/refund', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('This transaction cannot be refunded as the card used does not support refunds', $e->getMessage());
        }
    }

    #[Test]
    public function it_prefers_message_over_description_when_both_present(): void
    {
        $this->mockHttp->addResponse(new Response(400, [], '{"message":"short","description":"long form"}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('short', $e->getMessage());
        }
    }

    #[Test]
    public function it_extracts_message_from_nested_error_object(): void
    {
        $this->mockHttp->addResponse(new Response(400, [], '{"error":{"message":"nested"}}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('nested', $e->getMessage());
        }
    }

    #[Test]
    public function it_extracts_message_from_errors_array(): void
    {
        $this->mockHttp->addResponse(new Response(400, [], '{"errors":[{"message":"first error"},{"message":"second"}]}'));

        try {
            $this->httpClient->post('/checkouts', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('first error', $e->getMessage());
        }
    }

    #[Test]
    public function it_normalises_leading_slash_in_path(): void
    {
        $this->mockHttp->addResponse(new Response(200, [], '{}'));
        $this->httpClient->post('checkouts', []);

        $this->assertSame(
            'https://payments.yoco.com/api/checkouts',
            (string) $this->lastRequest()->getUri(),
        );
    }

    #[Test]
    public function it_trims_trailing_slash_from_base_uri(): void
    {
        $factory = new HttpFactory();
        $client = new HttpClient(
            secretKey: 'sk_test_abc',
            baseUri: 'https://payments.yoco.com/api/',
            httpClient: $this->mockHttp,
            requestFactory: $factory,
            streamFactory: $factory,
        );
        $this->mockHttp->addResponse(new Response(200, [], '{}'));

        $client->post('/checkouts', []);

        $this->assertSame(
            'https://payments.yoco.com/api/checkouts',
            (string) $this->lastRequest()->getUri(),
        );
    }

    private function lastRequest(): RequestInterface
    {
        $requests = $this->mockHttp->getRequests();
        $this->assertNotEmpty($requests);

        return $requests[count($requests) - 1];
    }
}
