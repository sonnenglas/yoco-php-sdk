<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Dto\LineItem;
use Sonnenglas\Yoco\Dto\PricingDetails;

final class CreateCheckoutRequestTest extends TestCase
{
    #[Test]
    public function it_serialises_minimal_payload(): void
    {
        $request = new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        );

        $this->assertSame([
            'amount' => 10000,
            'currency' => 'ZAR',
            'successUrl' => 'https://example.com/ok',
            'cancelUrl' => 'https://example.com/cancel',
        ], $request->toArray());
    }

    #[Test]
    public function it_includes_optional_fields_when_present(): void
    {
        $request = new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
            failureUrl: 'https://example.com/fail',
            metadata: ['orderNumber' => '12345'],
            lineItems: [
                new LineItem(
                    displayName: 'Widget',
                    quantity: 2,
                    pricingDetails: new PricingDetails(price: 5000),
                ),
            ],
            totalDiscount: 100,
            totalTaxAmount: 1500,
            subtotalAmount: 9900,
            externalId: 'order-12345',
        );

        $array = $request->toArray();

        $this->assertSame('https://example.com/fail', $array['failureUrl']);
        $this->assertSame(['orderNumber' => '12345'], $array['metadata']);
        $this->assertSame(100, $array['totalDiscount']);
        $this->assertSame(1500, $array['totalTaxAmount']);
        $this->assertSame(9900, $array['subtotalAmount']);
        $this->assertSame('order-12345', $array['externalId']);
        $this->assertSame([
            [
                'displayName' => 'Widget',
                'quantity' => 2,
                'pricingDetails' => ['price' => 5000],
            ],
        ], $array['lineItems']);
    }

    #[Test]
    public function it_rejects_amount_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amount must be at least 200');

        new CreateCheckoutRequest(
            amount: 100,
            currency: 'ZAR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        );
    }

    #[Test]
    public function it_rejects_non_zar_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Yoco only supports ZAR');

        new CreateCheckoutRequest(
            amount: 10000,
            currency: 'EUR',
            successUrl: 'https://example.com/ok',
            cancelUrl: 'https://example.com/cancel',
        );
    }

    #[Test]
    public function it_rejects_empty_success_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CreateCheckoutRequest(
            amount: 10000,
            currency: 'ZAR',
            successUrl: '',
            cancelUrl: 'https://example.com/cancel',
        );
    }
}
