<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use InvalidArgumentException;

final readonly class PricingDetails
{
    public function __construct(public int $price)
    {
        if ($price < 0) {
            throw new InvalidArgumentException('price must not be negative');
        }
    }

    /**
     * @return array{price: int}
     */
    public function toArray(): array
    {
        return ['price' => $this->price];
    }
}
