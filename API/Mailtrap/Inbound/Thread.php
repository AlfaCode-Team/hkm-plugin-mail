<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Inbound;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Conversation threads in an inbound inbox. */
final class Thread extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $inboxId)
    {
        parent::__construct($http);
    }

    /** @param ?string $lastId cursor from a previous page. @return array<mixed> */
    public function getList(?string $lastId = null): array
    {
        return $this->http->get($this->base(), $lastId === null ? [] : ['last_id' => $lastId]);
    }

    /** @return array<mixed> */
    public function getById(string $threadId): array
    {
        return $this->http->get($this->base() . '/' . $this->segment($threadId));
    }

    /** @return array<mixed> */
    public function delete(string $threadId): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($threadId));
    }

    public function getInboxId(): int
    {
        return $this->inboxId;
    }

    private function base(): string
    {
        return '/api/inbound/inboxes/' . $this->inboxId . '/threads';
    }
}
