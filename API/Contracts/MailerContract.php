<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Contracts;

use Plugins\Mail\Domain\Message;

/**
 * Rich mail API published by the Mail plugin (beyond the kernel MailPort's
 * view-based helpers). Other modules type-hint THIS to build full messages with
 * attachments, cc/bcc, inline images, priority, etc.
 *
 * Method names differ from MailPort's `send`/`queue` because the same class also
 * implements MailPort (PHP has no method overloading): use `dispatch`/`enqueue`
 * for a built Message, `send`/`queue` (MailPort) for the view-based shortcut.
 */
interface MailerContract
{
    /** A fresh message pre-filled with the configured default From. */
    public function message(): Message;

    /** Build + (optionally DKIM-sign) + deliver a Message now. */
    public function dispatch(Message $message): void;

    /** Enqueue a Message for background delivery via the QueuePort; returns the job id. */
    public function enqueue(Message $message): string;

    /**
     * Deliver many messages, in ONE provider request where the transport has a
     * batch endpoint and by looping where it does not — so a caller can always
     * use it and never has to ask which transport is configured.
     *
     * @param  list<Message> $messages one per recipient
     * @param  ?Message      $base     fields shared by all of them (from, subject,
     *                                 template, category…); it may NOT carry
     *                                 recipients of its own. Ignored by transports
     *                                 with no batch endpoint, which already have
     *                                 every field on each message.
     * @return list<list<string>>      provider message ids per message, in the
     *                                 order given; inner lists are empty for a
     *                                 transport that returns no ids (SMTP).
     */
    public function dispatchBatch(array $messages, ?Message $base = null): array;

    /** Build + (optionally DKIM-sign) the full MIME WITHOUT delivering — for tests/preview. */
    public function preview(Message $message): string;
}
