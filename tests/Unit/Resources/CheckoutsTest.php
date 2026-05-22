<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Resources;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\CheckoutResponse;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Exceptions\ApiException;
use Sonnenglas\Yoco\Exceptions\AuthenticationException;
use Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException;
use Sonnenglas\Yoco\Exceptions\ValidationException;
use Sonnenglas\Yoco\Http\HttpClient;
use Sonnenglas\Yoco\Resources\Checkouts;
use Sonnenglas\Yoco\Tests\Fixtures\FixtureLoader;

final class CheckoutsTest extends TestCase
{
    private MockClient $mock;

    private Checkouts $checkouts;

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
        $this->checkouts = new Checkouts($http);
    }

    #[Test]
    public function it_creates_a_checkout_and_parses_response(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
        ], JSON_THROW_ON_ERROR)));

        $response = $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));

        $this->assertInstanceOf(CheckoutResponse::class, $response);
        $this->assertSame('ch_123', $response->id);
        $this->assertSame('https://pay.yoco.com/ch_123', $response->redirectUrl);
        $this->assertSame('created', $response->status);
        $this->assertSame(10000, $response->amount);
        $this->assertSame('ZAR', $response->currency);
    }

    #[Test]
    public function it_sends_request_body_matching_yoco_schema(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
        ], JSON_THROW_ON_ERROR)));

        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
            metadata: ['orderNumber' => '777'],
        ));

        $request = $this->mock->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/checkouts', (string) $request->getUri());

        $decoded = json_decode((string) $request->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(10000, $decoded['amount']);
        $this->assertSame('ZAR', $decoded['currency']);
        $this->assertSame(['orderNumber' => '777'], $decoded['metadata']);
    }

    #[Test]
    public function it_propagates_authentication_exception_on_403(): void
    {
        $this->mock->addResponse(new Response(403, [], '{"message":"A key is required, but has not been specified."}'));

        $this->expectException(AuthenticationException::class);
        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function it_propagates_validation_exception_on_400(): void
    {
        $this->mock->addResponse(new Response(400, [], '{"message":"amount is required"}'));

        $this->expectException(ValidationException::class);
        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function it_propagates_idempotency_mismatch_on_422(): void
    {
        $this->mock->addResponse(new Response(422, [], '{"message":"The request payload does not match the original request."}'));

        $this->expectException(IdempotencyMismatchException::class);
        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));
    }

    #[Test]
    public function it_sends_user_provided_idempotency_key(): void
    {
        $this->mock->addResponse(new Response(200, [], $this->validCheckoutResponse()));

        $this->checkouts->create(
            new CreateCheckoutRequest(
                amount: 10000,
                currency: 'ZAR',
                successUrl: 'https://example.com/ok',
                cancelUrl: 'https://example.com/cancel',
            ),
            idempotencyKey: 'my-custom-key-123',
        );

        $request = $this->mock->getRequests()[0];
        $this->assertSame('my-custom-key-123', $request->getHeaderLine('Idempotency-Key'));
    }

    #[Test]
    public function it_auto_generates_idempotency_key_when_not_provided(): void
    {
        $this->mock->addResponse(new Response(200, [], $this->validCheckoutResponse()));

        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));

        $request = $this->mock->getRequests()[0];
        $key = $request->getHeaderLine('Idempotency-Key');
        $this->assertNotSame('', $key);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $key,
            'Expected an RFC 4122 v4 UUID',
        );
    }

    #[Test]
    public function it_uses_different_auto_generated_keys_for_each_call(): void
    {
        $this->mock->addResponse(new Response(200, [], $this->validCheckoutResponse()));
        $this->mock->addResponse(new Response(200, [], $this->validCheckoutResponse()));

        $request = new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        );
        $this->checkouts->create($request);
        $this->checkouts->create($request);

        $requests = $this->mock->getRequests();
        $this->assertNotSame(
            $requests[0]->getHeaderLine('Idempotency-Key'),
            $requests[1]->getHeaderLine('Idempotency-Key'),
        );
    }

    #[Test]
    public function it_refunds_a_checkout_fully_with_no_body(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'ch_abc',
            'refundId' => 'rf_123',
            'status' => 'pending',
            'message' => 'Refund processed',
        ], JSON_THROW_ON_ERROR)));

        $refund = $this->checkouts->refund('ch_abc');

        $request = $this->mock->getRequests()[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/checkouts/ch_abc/refund', (string) $request->getUri());
        // Full refund sends NO body and NO Content-Type — Yoco rejects `[]`.
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('', $request->getHeaderLine('Content-Type'));
        $this->assertNotSame('', $request->getHeaderLine('Idempotency-Key'));
        $this->assertSame('ch_abc', $refund->id);
        $this->assertSame('rf_123', $refund->refundId);
        $this->assertSame('pending', $refund->status);
    }

    #[Test]
    public function it_refunds_a_checkout_partially(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'ch_abc',
            'refundId' => 'rf_part',
            'status' => 'pending',
        ], JSON_THROW_ON_ERROR)));

        $this->checkouts->refund('ch_abc', amount: 2500);

        $request = $this->mock->getRequests()[0];
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $request->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(2500, $decoded['amount']);
    }

    #[Test]
    public function it_url_encodes_checkout_id_for_refund(): void
    {
        $this->mock->addResponse(new Response(200, [], json_encode([
            'id' => 'ch with/special',
            'refundId' => 'rf_x',
            'status' => 'pending',
        ], JSON_THROW_ON_ERROR)));

        $this->checkouts->refund('ch with/special');

        $request = $this->mock->getRequests()[0];
        $this->assertStringEndsWith('/checkouts/ch%20with%2Fspecial/refund', (string) $request->getUri());
    }

    #[Test]
    public function it_rejects_empty_checkout_id_on_refund(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->checkouts->refund('');
    }

    #[Test]
    public function it_rejects_zero_or_negative_refund_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->checkouts->refund('ch_abc', amount: 0);
    }

    private function validCheckoutResponse(): string
    {
        return (string) json_encode([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_parses_full_yoco_checkout_response_from_fixture(): void
    {
        $this->mock->addResponse(new Response(200, [], FixtureLoader::raw('checkout-response')));

        $response = $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));

        $this->assertSame('ch_9LVKD8GnAj7f39DFbn4F16bE', $response->id);
        $this->assertSame('p_LdcyhqMXcEsCsh4f72L8Vu76', $response->paymentId);
        $this->assertSame('test', $response->processingMode);
        $this->assertSame('mrch_abc123', $response->merchantId);
        $this->assertSame('order-99', $response->clientReferenceId);
    }

    #[Test]
    public function it_throws_when_response_lacks_required_fields(): void
    {
        $this->mock->addResponse(new Response(200, [], '{"status":"created"}'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Yoco checkout response missing required fields');

        $this->checkouts->create(new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        ));
    }
}
