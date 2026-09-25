<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sandbox;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Sandbox projects — the containers testing inboxes live in. */
final class Project extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getList(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getById(int $projectId): array
    {
        return $this->http->get($this->base() . '/' . $projectId);
    }

    /** @return array<mixed> */
    public function create(string $projectName): array
    {
        return $this->http->post($this->base(), ['project' => ['name' => $projectName]]);
    }

    /** Deletes the project AND every inbox in it. @return array<mixed> */
    public function delete(int $projectId): array
    {
        return $this->http->delete($this->base() . '/' . $projectId);
    }

    /** @return array<mixed> */
    public function updateName(int $projectId, string $projectName): array
    {
        return $this->http->patch($this->base() . '/' . $projectId, ['project' => ['name' => $projectName]]);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/projects';
    }
}
