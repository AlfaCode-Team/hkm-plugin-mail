<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sandbox;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\SandboxInboxUpdate;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Sandbox (Email Testing) inboxes.
 *
 * Note the asymmetry in the paths, which is the API's and not a mistake here:
 * an inbox is CREATED under a project, and addressed thereafter directly under
 * the account.
 */
final class Inbox extends AbstractMailtrapApi
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
    public function getInboxAttributes(int $inboxId): array
    {
        return $this->http->get($this->base() . '/' . $inboxId);
    }

    /** Created under a PROJECT, unlike every other call here. @return array<mixed> */
    public function create(int $projectId, string $inboxName): array
    {
        return $this->http->post(
            '/api/accounts/' . $this->accountId . '/projects/' . $projectId . '/inboxes',
            ['inbox' => ['name' => $inboxName]],
        );
    }

    /** @return array<mixed> */
    public function delete(int $inboxId): array
    {
        return $this->http->delete($this->base() . '/' . $inboxId);
    }

    /** @return array<mixed> */
    public function update(int $inboxId, SandboxInboxUpdate $update): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId, ['inbox' => $update->toArray()]);
    }

    /** Delete every message in the inbox. @return array<mixed> */
    public function clean(int $inboxId): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId . '/clean');
    }

    /** @return array<mixed> */
    public function markAsRead(int $inboxId): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId . '/all_read');
    }

    /**
     * Issue new SMTP credentials for the inbox. The previous pair stops working
     * immediately — anything still configured with it starts failing to connect.
     *
     * @return array<mixed>
     */
    public function resetSmtpCredentials(int $inboxId): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId . '/reset_credentials');
    }

    /** Enable or disable the inbox's e-mail address. @return array<mixed> */
    public function toggleEmailAddress(int $inboxId): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId . '/toggle_email_username');
    }

    /** @return array<mixed> */
    public function resetEmailAddress(int $inboxId): array
    {
        return $this->http->patch($this->base() . '/' . $inboxId . '/reset_email_username');
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/inboxes';
    }
}
