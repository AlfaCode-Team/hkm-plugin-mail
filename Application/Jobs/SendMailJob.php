<?php

declare(strict_types=1);

namespace Plugins\Mail\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Transport\MessageTransport;
use Plugins\Mail\Infrastructure\Transport\Transport;

/**
 * Delivers a message that was enqueued by Mailer::enqueue().
 *
 * The payload arrives in one of two shapes, chosen at enqueue time by the kind
 * of transport configured (see {@see \Plugins\Mail\Application\Mailer::jobPayload()}):
 *
 *   `from` + `recipients` + `mime`  a MIME transport (SMTP, sendmail, mail()).
 *                                   The MIME is already built and DKIM-signed,
 *                                   so the job just moves the bytes.
 *   `message`                       an API transport (Mailtrap). The serialised
 *                                   Message is rebuilt here and the request is
 *                                   composed in the worker, because the API
 *                                   takes a structured document, not MIME.
 *
 * A transport failure throws either way, triggering the worker's retry strategy.
 */
final class SendMailJob implements JobContract
{
    public function __construct(
        private readonly Transport $transport,
        /**
         * Optional so the job still resolves in an application that binds no
         * logger; when one IS bound the failure goes where an operator looks.
         */
        private readonly ?LoggerPort $logger = null,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $data = $payload->data();

        if (is_array($data['message'] ?? null)) {
            return $this->handleStructured($data['message']);
        }

        $mime = (string) ($data['mime'] ?? '');
        $from = (string) ($data['from'] ?? '');
        /** @var list<string> $recipients */
        $recipients = array_values((array) ($data['recipients'] ?? []));

        if ($mime === '' || $from === '' || $recipients === []) {
            return JobResult::skipped('Malformed mail payload.');
        }

        $this->transport->send($from, $recipients, $mime);

        return JobResult::success(['recipients' => count($recipients)]);
    }

    /**
     * Deliver a message that was queued for an API transport.
     *
     * A payload/transport mismatch THROWS rather than skipping. It means
     * MAIL_TRANSPORT changed while jobs were in flight, and skipping would
     * quietly delete real mail as "done"; throwing spends the retries and then
     * dead-letters through {@see self::failed()}, which is the one place an
     * operator finds out.
     *
     * @param array<string,mixed> $data
     */
    private function handleStructured(array $data): JobResult
    {
        if (!$this->transport instanceof MessageTransport) {
            throw new MailException(
                'A structured mail payload was queued for an API transport, but the configured '
                . 'transport delivers MIME. MAIL_TRANSPORT changed while this job was queued — '
                . 'restore the previous transport to drain the queue, or drop these jobs deliberately.',
            );
        }

        // fromArray() puts every address back through the Address constructor,
        // so a payload that has been sitting in a queue anyone can write to is
        // re-validated exactly like one built in PHP.
        $message = Message::fromArray($data);

        if ($message->recipientEmails() === [] || $message->getFrom() === null) {
            return JobResult::skipped('Malformed mail payload.');
        }

        $ids = $this->transport->sendMessage($message);

        return JobResult::success([
            'recipients'  => count($message->recipientEmails()),
            'message_ids' => $ids,
        ]);
    }

    /**
     * The message is dead-lettered: every retry is spent and this mail will
     * never be delivered. Nothing else reports it, so this is the ONLY record
     * an operator gets -- which is why it must not be an error_log().
     *
     * error_log() writes to the SAPI's error stream: stderr for a worker
     * started from a shell, and php.ini's error_log under systemd or a
     * supervisor. Neither is the application log, so a permanently failed mail
     * left no trace anywhere anyone looks, and a queue that quietly ate
     * messages was indistinguishable from one that was never drained. The
     * kernel forbids error_log() from a module for exactly this reason.
     *
     * The recipients and job id are logged, the MIME body is NOT: it carries
     * the verification links and personal content of the message that failed.
     */
    public function failed(JobPayload $payload, \Throwable $e): void
    {
        $data    = $payload->data();
        $context = [
            'job_id'     => $payload->jobId(),
            'queue'      => $payload->queue(),
            'attempts'   => $payload->attempts(),
            'error'      => $e->getMessage(),
            'exception'  => $e::class,
            ...$this->envelopeOf($data),
        ];

        if ($this->logger !== null) {
            $this->logger->critical('Mail permanently failed to deliver.', $context);

            return;
        }

        // No LoggerPort bound -- degrade rather than lose the event entirely.
        error_log('[mail] permanent delivery failure: ' . json_encode($context));
    }

    /**
     * Who the dead-lettered message was for, from either payload shape.
     *
     * Only the envelope: addresses and the sender. The bodies, the attachments,
     * the template variables and the custom variables are NOT logged -- the
     * whole reason this record exists is a failed verification or reset mail,
     * and its link is exactly what must not end up in a log file.
     *
     * @param  array<string,mixed> $data
     * @return array{recipients: list<string>, from: string}
     */
    private function envelopeOf(array $data): array
    {
        if (!is_array($data['message'] ?? null)) {
            return [
                'recipients' => array_values(array_map('strval', (array) ($data['recipients'] ?? []))),
                'from'       => (string) ($data['from'] ?? ''),
            ];
        }

        $message    = $data['message'];
        $recipients = [];

        foreach (['to', 'cc', 'bcc'] as $field) {
            foreach ((array) ($message[$field] ?? []) as $address) {
                if (is_array($address) && is_string($address['email'] ?? null)) {
                    $recipients[$address['email']] = true;
                }
            }
        }

        return [
            'recipients' => array_keys($recipients),
            'from'       => (string) ($message['from']['email'] ?? ''),
        ];
    }
}
