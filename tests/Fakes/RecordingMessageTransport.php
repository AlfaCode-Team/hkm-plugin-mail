<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail\Fakes;

use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Transport\MessageTransport;

/**
 * The structured counterpart to {@see \Plugins\Mail\Infrastructure\Transport\ArrayTransport}:
 * captures Messages instead of MIME, so a test can assert that the Mailer took
 * the API path at all — which is the thing that silently regresses.
 */
final class RecordingMessageTransport implements MessageTransport
{
    /** @var list<Message> */
    public array $messages = [];
    /** @var list<array{messages: list<Message>, base: ?Message}> */
    public array $batches = [];

    public bool $failNext = false;

    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        throw new MailException('This transport has no raw-MIME endpoint.');
    }

    public function sendMessage(Message $message): array
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new MailException('transport is down');
        }

        $this->messages[] = $message;

        return ['id-' . count($this->messages)];
    }

    public function sendBatch(array $messages, ?Message $base = null): array
    {
        $this->batches[] = ['messages' => array_values($messages), 'base' => $base];

        return array_map(static fn(int $i): array => ['batch-' . $i], array_keys(array_values($messages)));
    }

    public function last(): ?Message
    {
        return $this->messages[array_key_last($this->messages)] ?? null;
    }
}
