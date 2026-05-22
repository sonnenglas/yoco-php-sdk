<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\RefundEventPayload;
use Sonnenglas\Yoco\Dto\WebhookEvent;
use Sonnenglas\Yoco\Exceptions\ApiException;

final class RefundEventPayloadTest extends TestCase
{
    #[Test]
    public function it_parses_refund_succeeded_payload(): void
    {
        $parsed = RefundEventPayload::fromArray([
            'id' => 'rf_1',
            'amount' => 500,
            'currency' => 'ZAR',
            'status' => 'succeeded',
            'mode' => 'live',
            'refundableAmount' => 0,
            'metadata' => ['orderNumber' => 'ORD-100'],
        ]);

        $this->assertSame('rf_1', $parsed->id);
        $this->assertSame(500, $parsed->amount);
        $this->assertSame(0, $parsed->refundableAmount);
        $this->assertNull($parsed->failureReason);
    }

    #[Test]
    public function it_parses_refund_failed_with_failure_reason(): void
    {
        $parsed = RefundEventPayload::fromArray([
            'id' => 'rf_x',
            'amount' => 200,
            'currency' => 'ZAR',
            'status' => 'failed',
            'failureReason' => 'card does not support refunds',
        ]);

        $this->assertSame('failed', $parsed->status);
        $this->assertSame('card does not support refunds', $parsed->failureReason);
    }

    #[Test]
    public function webhook_event_returns_refund_payload_for_refund_types(): void
    {
        $event = new WebhookEvent(
            id: 'evt_1',
            type: 'refund.failed',
            createdDate: '2026-05-22T10:00:00Z',
            payload: ['id' => 'rf_1', 'amount' => 200, 'currency' => 'ZAR', 'status' => 'failed'],
        );

        $payload = $event->asRefundPayload();

        $this->assertNotNull($payload);
        $this->assertSame('failed', $payload->status);
        $this->assertNull($event->asPaymentPayload());
    }

    #[Test]
    public function it_rejects_payload_missing_required_fields(): void
    {
        $this->expectException(ApiException::class);
        RefundEventPayload::fromArray(['id' => 'rf_1']);
    }
}
