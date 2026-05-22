<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\WebhookSubscription;
use Sonnenglas\Yoco\Exceptions\ApiException;

final class WebhookSubscriptionTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $sub = new WebhookSubscription(
            id: 'wh_1',
            name: 'prod',
            url: 'https://example.com/hooks',
            mode: 'live',
            secret: 'whsec_super_secret_value_xyz',
        );

        $this->assertSame('wh_1', $sub->id);
        $this->assertSame('whsec_super_secret_value_xyz', $sub->secret);
    }

    #[Test]
    public function it_redacts_secret_in_debug_info(): void
    {
        $sub = new WebhookSubscription(
            id: 'wh_1',
            name: 'prod',
            url: 'https://example.com/hooks',
            mode: 'live',
            secret: 'whsec_super_secret_value_xyz',
        );

        $debug = print_r($sub, true);
        $this->assertStringNotContainsString('whsec_super_secret_value_xyz', $debug);
        $this->assertStringContainsString('***redacted***', $debug);

        $varDump = $this->captureVarDump($sub);
        $this->assertStringNotContainsString('whsec_super_secret_value_xyz', $varDump);
    }

    #[Test]
    public function it_does_not_redact_secret_when_it_is_null(): void
    {
        $sub = new WebhookSubscription(
            id: 'wh_1',
            name: 'prod',
            url: 'https://example.com/hooks',
            mode: 'live',
            secret: null,
        );

        $debug = print_r($sub, true);
        $this->assertStringNotContainsString('***redacted***', $debug);
    }

    #[Test]
    public function from_array_throws_when_required_fields_missing(): void
    {
        $this->expectException(ApiException::class);
        WebhookSubscription::fromArray(['id' => 'wh_1']);
    }

    #[Test]
    public function from_array_throws_when_secret_is_non_string_non_null(): void
    {
        $this->expectException(ApiException::class);
        WebhookSubscription::fromArray([
            'id' => 'wh_1',
            'name' => 'prod',
            'url' => 'https://example.com/hooks',
            'mode' => 'live',
            'secret' => 42,
        ]);
    }

    #[Test]
    public function from_array_throws_when_mode_missing(): void
    {
        $this->expectException(ApiException::class);
        WebhookSubscription::fromArray([
            'id' => 'wh_1',
            'name' => 'prod',
            'url' => 'https://example.com/hooks',
        ]);
    }

    private function captureVarDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
