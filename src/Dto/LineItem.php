<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use InvalidArgumentException;

final readonly class LineItem
{
    public function __construct(
        public string $displayName,
        public int $quantity,
        public PricingDetails $pricingDetails,
        public ?string $description = null,
        public ?int $totalDiscount = null,
        public ?int $totalTaxAmount = null,
    ) {
        if ($displayName === '') {
            throw new InvalidArgumentException('displayName must not be empty');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity must be at least 1');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'displayName' => $this->displayName,
            'quantity' => $this->quantity,
            'pricingDetails' => $this->pricingDetails->toArray(),
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->totalDiscount !== null) {
            $data['totalDiscount'] = $this->totalDiscount;
        }

        if ($this->totalTaxAmount !== null) {
            $data['totalTaxAmount'] = $this->totalTaxAmount;
        }

        return $data;
    }
}
