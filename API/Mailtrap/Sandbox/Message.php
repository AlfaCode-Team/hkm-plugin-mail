<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sandbox;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;

/**
 * Messages captured in a sandbox inbox — the assertion surface for a test.
 *
 * Five of these return the message ITSELF rather than JSON (`getText`,
 * `getRaw`, `getHtml`, `getEml`, `getSource`), so they are typed `string`.
 * Putting them through the JSON decoder would yield null and lose the content,
 * which is exactly what the caller asked for.
 */
final class Message extends AbstractMailtrapApi
{
    public function __construct(
        MailtrapHttp $http,
        private readonly int $accountId,
        private readonly int $inboxId,
    ) {
        parent::__construct($http);
    }

    /**
     * @param  ?int    $page          1-based
     * @param  ?string $search        matches subject, to and from
     * @param  ?int    $lastMessageId cursor — messages after this id
     * @return array<mixed>
     */
    public function getList(?int $page = null, ?string $search = null, ?int $lastMessageId = null): array
    {
        $query = array_filter([
            'page'    => $page,
            'search'  => $search,
            'last_id' => $lastMessageId,
        ], static fn(mixed $value): bool => $value !== null);

        return $this->http->get($this->base(), $query);
    }

    /** @return array<mixed> */
    public function getById(int $messageId): array
    {
        return $this->http->get($this->base() . '/' . $messageId);
    }

    /** SpamAssassin's verdict on the message. @return array<mixed> */
    public function getSpamScore(int $messageId): array
    {
        return $this->http->get($this->base() . '/' . $messageId . '/spam_report');
    }

    /** HTML-support analysis across mail clients. @return array<mixed> */
    public function getHtmlAnalysis(int $messageId): array
    {
        return $this->http->get($this->base() . '/' . $messageId . '/analyze');
    }

    /** @return array<mixed> */
    public function getMailHeaders(int $messageId): array
    {
        return $this->http->get($this->base() . '/' . $messageId . '/mail_headers');
    }

    /** The plain-text body, verbatim. */
    public function getText(int $messageId): string
    {
        return $this->http->getRaw($this->base() . '/' . $messageId . '/body.txt');
    }

    /** The raw MIME source. */
    public function getRaw(int $messageId): string
    {
        return $this->http->getRaw($this->base() . '/' . $messageId . '/body.raw');
    }

    /** The HTML body, verbatim. */
    public function getHtml(int $messageId): string
    {
        return $this->http->getRaw($this->base() . '/' . $messageId . '/body.html');
    }

    /** The whole message as a downloadable .eml. */
    public function getEml(int $messageId): string
    {
        return $this->http->getRaw($this->base() . '/' . $messageId . '/body.eml');
    }

    /** The HTML body as SOURCE — escaped for display, not for rendering. */
    public function getSource(int $messageId): string
    {
        return $this->http->getRaw($this->base() . '/' . $messageId . '/body.htmlsource');
    }

    /** @return array<mixed> */
    public function markAsRead(int $messageId, bool $isRead = true): array
    {
        return $this->http->patch($this->base() . '/' . $messageId, ['message' => ['is_read' => $isRead]]);
    }

    /** @return array<mixed> */
    public function delete(int $messageId): array
    {
        return $this->http->delete($this->base() . '/' . $messageId);
    }

    /**
     * Forward a captured message to a real address.
     *
     * The recipient must be confirmed in the Mailtrap account — a sandbox that
     * could forward anywhere would be an open relay.
     *
     * @return array<mixed>
     */
    public function forward(int $messageId, string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Sandbox\\Message::forward: invalid recipient: {$email}");
        }

        return $this->http->post($this->base() . '/' . $messageId . '/forward', ['email' => $email]);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getInboxId(): int
    {
        return $this->inboxId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/inboxes/' . $this->inboxId . '/messages';
    }
}
