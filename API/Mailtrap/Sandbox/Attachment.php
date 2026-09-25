<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sandbox;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Attachments on a message captured in a sandbox inbox. */
final class Attachment extends AbstractMailtrapApi
{
    public function __construct(
        MailtrapHttp $http,
        private readonly int $accountId,
        private readonly int $inboxId,
    ) {
        parent::__construct($http);
    }

    /**
     * @param  ?string $attachmentType narrow to a disposition — e.g. 'inline', 'attachment'
     * @return array<mixed>
     */
    public function getMessageAttachments(int $messageId, ?string $attachmentType = null): array
    {
        return $this->http->get(
            $this->base($messageId),
            $attachmentType === null || $attachmentType === '' ? [] : ['attachment_type' => $attachmentType],
        );
    }

    /** Metadata for one attachment, including its download path. @return array<mixed> */
    public function getMessageAttachment(int $messageId, int $attachmentId): array
    {
        return $this->http->get($this->base($messageId) . '/' . $attachmentId);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getInboxId(): int
    {
        return $this->inboxId;
    }

    private function base(int $messageId): string
    {
        return '/api/accounts/' . $this->accountId . '/inboxes/' . $this->inboxId
            . '/messages/' . $messageId . '/attachments';
    }
}
