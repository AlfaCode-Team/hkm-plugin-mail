<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Inbound;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message as MailMessage;
use Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload;

/**
 * Messages received in an inbound inbox, and the three ways to answer one.
 *
 * `reply`, `replyAll` and `forward` SEND REAL MAIL. They take the plugin's own
 * {@see MailMessage}, built through the same
 * {@see \Plugins\Mail\API\Contracts\MailerContract::message()} as anything else,
 * so composing a reply is the same job as composing any other mail — including
 * attachments, a category, and a stored template.
 *
 * `reply`/`replyAll` need NO recipient: Mailtrap addresses them from the message
 * being answered. Supplying one is allowed and overrides that; `forward` demands
 * at least one, since there is nobody to infer.
 */
final class Message extends AbstractMailtrapApi
{
    public function __construct(
        MailtrapHttp $http,
        private readonly int $inboxId,
        private readonly MailtrapPayload $payload = new MailtrapPayload(),
    ) {
        parent::__construct($http);
    }

    /** @param ?string $lastId cursor from a previous page. @return array<mixed> */
    public function getList(?string $lastId = null): array
    {
        return $this->http->get($this->base(), $lastId === null ? [] : ['last_id' => $lastId]);
    }

    /** @return array<mixed> */
    public function getById(string $messageId): array
    {
        return $this->http->get($this->base() . '/' . $this->segment($messageId));
    }

    /** @return array<mixed> */
    public function delete(string $messageId): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($messageId));
    }

    /** Answer the original sender. Sends real mail. @return array<mixed> */
    public function reply(string $messageId, MailMessage $message): array
    {
        return $this->http->post(
            $this->base() . '/' . $this->segment($messageId) . '/reply',
            $this->payload->buildReply($message),
        );
    }

    /** Answer the sender AND the original's other recipients. Sends real mail. @return array<mixed> */
    public function replyAll(string $messageId, MailMessage $message): array
    {
        return $this->http->post(
            $this->base() . '/' . $this->segment($messageId) . '/reply_all',
            $this->payload->buildReply($message),
        );
    }

    /** Pass the message on to new recipients. Sends real mail. @return array<mixed> */
    public function forward(string $messageId, MailMessage $message): array
    {
        if ($message->getTo() === []) {
            throw new MailException(
                'Inbound\\Message::forward: a forward needs at least one "to" recipient — '
                . 'unlike a reply, there is nobody to infer it from.',
            );
        }

        return $this->http->post(
            $this->base() . '/' . $this->segment($messageId) . '/forward',
            $this->payload->buildReply($message),
        );
    }

    public function getInboxId(): int
    {
        return $this->inboxId;
    }

    private function base(): string
    {
        return '/api/inbound/inboxes/' . $this->inboxId . '/messages';
    }
}
