<?php

declare(strict_types=1);

namespace Plugins\Mail\API\DTOs;

use Plugins\Mail\Domain\MailException;

/**
 * Mailtrap API credentials and stream, supplied by the CALLER rather than this
 * plugin's env-driven `mail.mailtrap` config — the shape a consuming project
 * passes to {@see \Plugins\Mail\API\Contracts\MailerFactoryContract::forMailtrap()}
 * to send through a tenant's own Mailtrap account (or into a per-feature
 * sandbox inbox) while the configured mailer keeps serving everything else.
 *
 * The API token is a bearer credential: it is sent in an `Authorization` header
 * and grants the sending rights of whoever issued it. It is therefore treated
 * exactly like {@see SmtpSettings::$password} — redacted from `__debugInfo()`,
 * from `__toString()`, and (via `#[\SensitiveParameter]`) from any stack trace.
 * The one PHP debug function this does NOT cover is `var_export()`, which reads
 * real property values and has no redaction hook — do not `var_export()` this
 * class.
 *
 * Validation happens HERE, not at send time, so a misconfiguration surfaces
 * when the mailer is built rather than as a 401/404 on the first real message.
 */
final readonly class MailtrapSettings
{
    public string $apiToken;
    public MailtrapStream $stream;
    public ?int $inboxId;
    public string $hostOverride;
    public int $timeout;
    public string $fromEmail;
    public string $fromName;

    /**
     * @param string $apiToken     Mailtrap API token (`Api-Token` / bearer)
     * @param ?int   $inboxId      REQUIRED for the sandbox stream — it is part of the URL path
     * @param string $hostOverride '' = the stream's own host; set only to point at a proxy or a mock
     * @param string $fromEmail    default From for mail sent through this mailer; '' = the configured MAIL_FROM_ADDRESS
     * @param string $fromName     default From display name; '' = the configured MAIL_FROM_NAME
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiToken,
        MailtrapStream $stream = MailtrapStream::Transactional,
        ?int $inboxId = null,
        string $hostOverride = '',
        int $timeout = 30,
        string $fromEmail = '',
        string $fromName = '',
    ) {
        $apiToken     = trim($apiToken);
        $hostOverride = trim($hostOverride);

        if ($apiToken === '') {
            throw new MailException('MailtrapSettings: an API token is required (set MAILTRAP_API_TOKEN).');
        }
        // The token goes into an Authorization header verbatim. A CR/LF in it
        // would be header injection on the OUTBOUND request, so it is refused
        // here rather than left for the HTTP client to notice (or not).
        if (preg_match('/[\r\n\x00]/', $apiToken) === 1) {
            throw new MailException('MailtrapSettings: the API token may not contain control characters.');
        }
        if ($stream->needsInbox() && ($inboxId === null || $inboxId < 1)) {
            throw new MailException(
                'MailtrapSettings: the sandbox stream needs an inbox id (set MAILTRAP_INBOX_ID) — '
                . 'it is part of the request path, not the payload.',
            );
        }
        if ($hostOverride !== '' && preg_match('#^[A-Za-z0-9.\-]+(:\d{1,5})?$#', $hostOverride) !== 1) {
            throw new MailException("MailtrapSettings: invalid host override '{$hostOverride}'.");
        }
        if ($timeout < 1) {
            throw new MailException("MailtrapSettings: timeout must be at least 1 second, got {$timeout}.");
        }

        $this->apiToken     = $apiToken;
        $this->stream       = $stream;
        $this->inboxId      = $stream->needsInbox() ? $inboxId : null;
        $this->hostOverride = $hostOverride;
        $this->timeout      = $timeout;
        $this->fromEmail    = trim($fromEmail);
        $this->fromName     = trim($fromName);
    }

    /** The host requests go to — the override when set, else the stream's own. */
    public function host(): string
    {
        return $this->hostOverride !== '' ? $this->hostOverride : $this->stream->host();
    }

    /** Single-message endpoint. Sandbox carries the inbox id in the path. */
    public function sendPath(): string
    {
        return $this->inboxId !== null ? "/api/send/{$this->inboxId}" : '/api/send';
    }

    /** Batch endpoint (one base + up to 500 per-recipient requests). */
    public function batchPath(): string
    {
        return $this->inboxId !== null ? "/api/batch/{$this->inboxId}" : '/api/batch';
    }

    /** var_dump()-safe: the token is redacted, never printed in the clear. */
    public function __debugInfo(): array
    {
        return [
            'apiToken'     => '••••••',
            'stream'       => $this->stream->value,
            'inboxId'      => $this->inboxId,
            'hostOverride' => $this->hostOverride === '' ? '(stream default)' : $this->hostOverride,
            'timeout'      => $this->timeout,
            'fromEmail'    => $this->fromEmail,
            'fromName'     => $this->fromName,
        ];
    }

    /** Log-safe summary — the token is NEVER included. */
    public function __toString(): string
    {
        return sprintf(
            'MailtrapSettings(stream: %s, host: %s, inbox: %s)',
            $this->stream->value,
            $this->host(),
            $this->inboxId !== null ? (string) $this->inboxId : '(n/a)',
        );
    }
}
