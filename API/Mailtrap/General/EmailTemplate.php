<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\EmailTemplateData;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Stored email templates — the things `Message::template($uuid)` renders.
 *
 * This API addresses them by numeric ID; SENDING uses the template's UUID. They
 * are different identifiers on the same object, and the send API rejects the id.
 */
final class EmailTemplate extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getAllEmailTemplates(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getEmailTemplate(int $templateId): array
    {
        return $this->http->get($this->base() . '/' . $templateId);
    }

    /** @return array<mixed> */
    public function createEmailTemplate(EmailTemplateData $template): array
    {
        return $this->http->post($this->base(), ['email_template' => $template->toArray()]);
    }

    /** @return array<mixed> */
    public function updateEmailTemplate(int $templateId, EmailTemplateData $template): array
    {
        return $this->http->patch($this->base() . '/' . $templateId, ['email_template' => $template->toArray()]);
    }

    /** @return array<mixed> */
    public function deleteEmailTemplate(int $templateId): array
    {
        return $this->http->delete($this->base() . '/' . $templateId);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/email_templates';
    }
}
