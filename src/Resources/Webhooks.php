<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Resources;

use Sonnenglas\Yoco\Dto\WebhookSubscription;
use Sonnenglas\Yoco\Exceptions\ApiException;

class Webhooks extends BaseResource
{
    public function create(string $name, string $url): WebhookSubscription
    {
        $response = $this->http->post('/webhooks', [
            'name' => $name,
            'url' => $url,
        ]);

        return WebhookSubscription::fromArray($response);
    }

    /**
     * @return list<WebhookSubscription>
     */
    public function list(): array
    {
        $response = $this->http->get('/webhooks');

        if (! array_key_exists('subscriptions', $response)) {
            throw new ApiException(
                'Yoco webhook list response missing "subscriptions" key',
                0,
                $response,
            );
        }

        $subscriptions = $response['subscriptions'];

        if (! is_array($subscriptions)) {
            throw new ApiException('Yoco webhook list response is malformed', 0, $response);
        }

        $result = [];
        foreach ($subscriptions as $item) {
            if (! is_array($item)) {
                throw new ApiException('Yoco webhook list entry is malformed', 0, $response);
            }

            /** @var array<string, mixed> $item */
            $result[] = WebhookSubscription::fromArray($item);
        }

        return $result;
    }

    public function delete(string $id): void
    {
        $this->http->delete('/webhooks/'.rawurlencode($id));
    }
}
