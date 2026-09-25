<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use Plugins\Mail\Domain\Message;

/**
 * A transport that delivers the MESSAGE rather than a block of MIME bytes.
 *
 * {@see Transport} is the right shape for anything that speaks SMTP or hands
 * bytes to a binary: the Mailer builds the MIME, signs it, and the transport
 * moves it. A provider HTTP API is the other shape — Mailtrap's `/api/send`
 * takes a structured JSON document and builds the MIME itself, so there is no
 * point at which raw MIME is the thing being sent, and no place to put a
 * locally computed DKIM signature.
 *
 * Rather than pretend otherwise (parsing MIME back into fields would lose
 * exactly the structure the API wants), transports of that kind implement this
 * interface and {@see \Plugins\Mail\Application\Mailer} routes to
 * `sendMessage()` when it sees one. The `Transport::send()` half stays on the
 * interface so a single `Transport` binding still satisfies every consumer;
 * an API transport rejects it with an explanation instead of guessing.
 */
interface MessageTransport extends Transport
{
    /**
     * Deliver one message.
     *
     * @return list<string> provider message ids, empty when the provider
     *                      returns none — never null, so a caller can log the
     *                      ids without checking which transport it holds
     */
    public function sendMessage(Message $message): array;

    /**
     * Deliver many messages in ONE request.
     *
     * @param  list<Message> $messages  one per recipient
     * @param  ?Message      $base      fields shared by every entry; it may NOT
     *                                  carry recipients of its own
     * @return list<list<string>>       per-message ids, in the order given
     */
    public function sendBatch(array $messages, ?Message $base = null): array;
}
