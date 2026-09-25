<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateWebhook;
use Plugins\Mail\API\Mailtrap\DTO\UpdateWebhook;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Webhook subscriptions.
 *
 * The create response carries the `signing_secret` — keep it: it is what
 * {@see \Plugins\Mail\API\MailtrapWebhookSignature::verify()} needs, and an
 * endpoint that does not verify is an unauthenticated public write.
 */
final class Webhook extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getWebhooks(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getWebhook(int $webhookId): array
    {
        return $this->http->get($this->base() . '/' . $webhookId);
    }

    /** @return array<mixed> */
    public function createWebhook(CreateWebhook $webhook): array
    {
        return $this->http->post($this->base(), ['webhook' => $webhook->toArray()]);
    }

    /** @return array<mixed> */
    public function updateWebhook(int $webhookId, UpdateWebhook $webhook): array
    {
        return $this->http->patch($this->base() . '/' . $webhookId, ['webhook' => $webhook->toArray()]);
    }

    /** @return array<mixed> */
    public function deleteWebhook(int $webhookId): array
    {
        return $this->http->delete($this->base() . '/' . $webhookId);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/webhooks';
    }
}
