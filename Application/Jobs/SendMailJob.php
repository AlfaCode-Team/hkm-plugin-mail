<?php

declare(strict_types=1);

namespace Plugins\Mail\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Infrastructure\Transport\Transport;

/**
 * Delivers a message that was enqueued by Mailer::enqueue(). The MIME is already
 * built and DKIM-signed at enqueue time, so the job just moves the bytes — a
 * transport failure throws, triggering the worker's retry strategy.
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
            'recipients' => array_values((array) ($data['recipients'] ?? [])),
            'from'       => (string) ($data['from'] ?? ''),
            'error'      => $e->getMessage(),
            'exception'  => $e::class,
        ];

        if ($this->logger !== null) {
            $this->logger->critical('Mail permanently failed to deliver.', $context);

            return;
        }

        // No LoggerPort bound -- degrade rather than lose the event entirely.
        error_log('[mail] permanent delivery failure: ' . json_encode($context));
    }
}
