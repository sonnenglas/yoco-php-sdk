<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

final readonly class CheckoutResponse
{
    /**
     * @param array<string, mixed>|null      $metadata
     * @param list<array<string, mixed>>|null $lineItems Raw line items echoed by Yoco.
     */
    public function __construct(
        public string $id,
        public string $redirectUrl,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $paymentId = null,
        public ?string $processingMode = null,
        public ?string $merchantId = null,
        public ?string $clientReferenceId = null,
        public ?string $successUrl = null,
        public ?string $cancelUrl = null,
        public ?string $failureUrl = null,
        public ?array $metadata = null,
        public ?array $lineItems = null,
        public ?int $subtotalAmount = null,
        public ?int $totalDiscount = null,
        public ?int $totalTaxAmount = null,
        public ?string $externalId = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $redirectUrl = $data['redirectUrl'] ?? null;
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        if (! is_string($id)
            || ! is_string($redirectUrl)
            || ! is_string($status)
            || ! is_int($amount)
            || ! is_string($currency)
        ) {
            throw new ApiException('Yoco checkout response missing required fields', 0, $data);
        }

        return new self(
            id: $id,
            redirectUrl: $redirectUrl,
            status: $status,
            amount: $amount,
            currency: $currency,
            paymentId: self::optionalString($data, 'paymentId'),
            processingMode: self::optionalString($data, 'processingMode'),
            merchantId: self::optionalString($data, 'merchantId'),
            clientReferenceId: self::optionalString($data, 'clientReferenceId'),
            successUrl: self::optionalString($data, 'successUrl'),
            cancelUrl: self::optionalString($data, 'cancelUrl'),
            failureUrl: self::optionalString($data, 'failureUrl'),
            metadata: self::optionalAssocArray($data, 'metadata'),
            lineItems: self::optionalListOfArrays($data, 'lineItems'),
            subtotalAmount: self::optionalInt($data, 'subtotalAmount'),
            totalDiscount: self::optionalInt($data, 'totalDiscount'),
            totalTaxAmount: self::optionalInt($data, 'totalTaxAmount'),
            externalId: self::optionalString($data, 'externalId'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new ApiException("Yoco checkout {$field} must be a string when present", 0, $data);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalInt(array $data, string $field): ?int
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value)) {
            throw new ApiException("Yoco checkout {$field} must be an int when present", 0, $data);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private static function optionalAssocArray(array $data, string $field): ?array
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new ApiException("Yoco checkout {$field} must be an object when present", 0, $data);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private static function optionalListOfArrays(array $data, string $field): ?array
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new ApiException("Yoco checkout {$field} must be an array when present", 0, $data);
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new ApiException("Yoco checkout {$field}[] entries must be objects", 0, $data);
            }
            /** @var array<string, mixed> $item */
            $list[] = $item;
        }

        return $list;
    }
}
