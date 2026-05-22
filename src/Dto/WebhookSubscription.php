<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

final readonly class WebhookSubscription
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $mode,
        public ?string $secret = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $url = $data['url'] ?? null;
        $mode = $data['mode'] ?? null;
        $secret = $data['secret'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($url) || ! is_string($mode)) {
            throw new ApiException('Yoco webhook response missing required fields (id, name, url, mode)', 0, $data);
        }

        if ($secret !== null && ! is_string($secret)) {
            throw new ApiException('Yoco webhook secret must be a string when present', 0, $data);
        }

        return new self(id: $id, name: $name, url: $url, mode: $mode, secret: $secret);
    }

    /**
     * Hide the webhook secret from var_dump / print_r / Symfony VarDumper
     * to reduce the risk of accidentally logging it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'mode' => $this->mode,
            'secret' => $this->secret === null ? null : '***redacted***',
        ];
    }
}
