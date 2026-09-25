<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Inbound;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateInboundInbox;
use Plugins\Mail\API\Mailtrap\DTO\UpdateInboundInbox;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Inbound inboxes — where mail SENT TO you arrives.
 *
 * Unrelated to {@see \Plugins\Mail\API\Mailtrap\Sandbox\Inbox}, which captures
 * mail your application sends. Same word, opposite direction.
 */
final class Inbox extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $folderId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getList(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getById(int $inboxId): array
    {
        return $this->http->get($this->base() . '/' . $inboxId);
    }

    /** @return array<mixed> */
    public function create(CreateInboundInbox $inbox): array
    {
        return $this->http->post($this->base(), $inbox->toArray());
    }

    /** @return array<mixed> */
    public function update(int $inboxId, UpdateInboundInbox $inbox): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId, $inbox->toArray());
    }

    /** @return array<mixed> */
    public function delete(int $inboxId): array
    {
        return $this->http->delete($this->base() . '/' . $inboxId);
    }

    public function getFolderId(): int
    {
        return $this->folderId;
    }

    private function base(): string
    {
        return '/api/inbound/folders/' . $this->folderId . '/inboxes';
    }
}
