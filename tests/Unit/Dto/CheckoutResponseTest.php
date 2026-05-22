<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\CheckoutResponse;
use Sonnenglas\Yoco\Exceptions\ApiException;

final class CheckoutResponseTest extends TestCase
{
    #[Test]
    public function it_parses_minimal_required_fields(): void
    {
        $response = CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
        ]);

        $this->assertSame('ch_123', $response->id);
        $this->assertNull($response->paymentId);
        $this->assertNull($response->processingMode);
        $this->assertNull($response->merchantId);
        $this->assertNull($response->clientReferenceId);
    }

    #[Test]
    public function it_parses_extended_yoco_fields(): void
    {
        $response = CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
            'paymentId' => 'p_abc',
            'processingMode' => 'test',
            'merchantId' => 'merchant_42',
            'clientReferenceId' => 'order-99',
        ]);

        $this->assertSame('p_abc', $response->paymentId);
        $this->assertSame('test', $response->processingMode);
        $this->assertSame('merchant_42', $response->merchantId);
        $this->assertSame('order-99', $response->clientReferenceId);
    }

    #[Test]
    public function it_parses_echoed_request_fields(): void
    {
        $response = CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/ch_123',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
            'successUrl' => 'https://example.com/success',
            'cancelUrl' => 'https://example.com/cancel',
            'failureUrl' => 'https://example.com/failure',
            'metadata' => ['orderNumber' => 'ORD-100'],
            'lineItems' => [['displayName' => 'Widget', 'quantity' => 1]],
            'subtotalAmount' => 9000,
            'totalDiscount' => 0,
            'totalTaxAmount' => 1000,
            'externalId' => 'ORD-100',
        ]);

        $this->assertSame('https://example.com/success', $response->successUrl);
        $this->assertSame('https://example.com/cancel', $response->cancelUrl);
        $this->assertSame('https://example.com/failure', $response->failureUrl);
        $this->assertSame(['orderNumber' => 'ORD-100'], $response->metadata);
        $this->assertSame([['displayName' => 'Widget', 'quantity' => 1]], $response->lineItems);
        $this->assertSame(9000, $response->subtotalAmount);
        $this->assertSame(0, $response->totalDiscount);
        $this->assertSame(1000, $response->totalTaxAmount);
        $this->assertSame('ORD-100', $response->externalId);
    }

    #[Test]
    public function it_throws_when_metadata_is_not_an_object(): void
    {
        $this->expectException(ApiException::class);
        CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/x',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
            'metadata' => 'should-be-object',
        ]);
    }

    #[Test]
    public function it_throws_when_id_is_wrong_type(): void
    {
        $this->expectException(ApiException::class);
        CheckoutResponse::fromArray([
            'id' => 12345,
            'redirectUrl' => 'https://pay.yoco.com/x',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
        ]);
    }

    #[Test]
    public function it_throws_when_amount_is_string_instead_of_int(): void
    {
        $this->expectException(ApiException::class);
        CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/x',
            'status' => 'created',
            'amount' => '10000',
            'currency' => 'ZAR',
        ]);
    }

    #[Test]
    public function it_throws_when_processing_mode_is_wrong_type(): void
    {
        $this->expectException(ApiException::class);
        CheckoutResponse::fromArray([
            'id' => 'ch_123',
            'redirectUrl' => 'https://pay.yoco.com/x',
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'ZAR',
            'processingMode' => 42,
        ]);
    }
}
