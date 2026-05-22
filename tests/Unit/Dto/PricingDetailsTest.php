<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\PricingDetails;

final class PricingDetailsTest extends TestCase
{
    #[Test]
    public function it_constructs_and_serialises(): void
    {
        $pd = new PricingDetails(price: 1500);

        $this->assertSame(1500, $pd->price);
        $this->assertSame(['price' => 1500], $pd->toArray());
    }

    #[Test]
    public function it_allows_price_of_zero_for_free_line_items(): void
    {
        // E.g. free shipping or a promotional voucher item.
        $pd = new PricingDetails(price: 0);
        $this->assertSame(0, $pd->price);
    }

    #[Test]
    public function it_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('price must not be negative');
        new PricingDetails(price: -1);
    }
}
