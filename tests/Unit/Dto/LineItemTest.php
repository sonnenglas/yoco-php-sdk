<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\LineItem;
use Sonnenglas\Yoco\Dto\PricingDetails;

final class LineItemTest extends TestCase
{
    #[Test]
    public function it_serialises_minimal_line_item(): void
    {
        $item = new LineItem(
            displayName: 'Widget',
            quantity: 2,
            pricingDetails: new PricingDetails(price: 5000),
        );

        $this->assertSame([
            'displayName' => 'Widget',
            'quantity' => 2,
            'pricingDetails' => ['price' => 5000],
        ], $item->toArray());
    }

    #[Test]
    public function it_serialises_all_optional_fields_when_present(): void
    {
        $item = new LineItem(
            displayName: 'Widget',
            quantity: 1,
            pricingDetails: new PricingDetails(price: 1000),
            description: 'A nice widget',
            totalDiscount: 100,
            totalTaxAmount: 150,
        );

        $array = $item->toArray();
        $this->assertSame('A nice widget', $array['description']);
        $this->assertSame(100, $array['totalDiscount']);
        $this->assertSame(150, $array['totalTaxAmount']);
    }

    #[Test]
    public function it_rejects_empty_display_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('displayName must not be empty');
        new LineItem(displayName: '', quantity: 1, pricingDetails: new PricingDetails(1));
    }

    #[Test]
    public function it_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be at least 1');
        new LineItem(displayName: 'X', quantity: 0, pricingDetails: new PricingDetails(1));
    }

    #[Test]
    public function it_rejects_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LineItem(displayName: 'X', quantity: -5, pricingDetails: new PricingDetails(1));
    }
}
