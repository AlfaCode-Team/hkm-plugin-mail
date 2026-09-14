<?php

declare(strict_types=1);

namespace Plugins\Mail\API\DTOs;

use Plugins\Mail\Domain\MailException;

/**
 * SMTP connection settings supplied by the CALLER rather than this plugin's
 * env-driven `mail.smtp` config — the shape a consuming project passes to
 * {@see \Plugins\Mail\API\Contracts\MailerFactoryContract::forSmtp()} to send
 * through, say, a tenant's own outbound mail server.
 *
 * Only what a tenant actually owns lives here: where to connect (hosts/port/
 * encryption), how to authenticate (username/password), and its own default
 * From. Every OTHER transport tunable (auth mode, OAuth token, HELO domain,
 * timeout, peer verification, keep-alive, insecure-auth), the charset and DKIM
 * still come from the plugin's configured `mail.*` — see
 * {@see \Plugins\Mail\Infrastructure\Transport\TransportFactory}.
 *
 * SECURITY: `$password` is never included in an exception message, `__toString()`,
 * `print_r()`/`var_dump()` output — see {@see self::__debugInfo()} / {@see
 * self::__toString()} — or an uncaught exception's stack trace, via
 * `#[\SensitiveParameter]` on the constructor argument. The one PHP debug
 * function this does NOT cover is `var_export()`, which reads real property
 * values directly and has no redaction hook — do not `var_export()` this class.
 */
final readonly class SmtpSettings
{
    /** @var list<string> */
    public array $hosts;
    public int $port;
    public string $encryption;
    public string $username;
    public string $password;
    public string $fromEmail;
    public string $fromName;

    /**
     * @param list<string>       $hosts      ordered failover list — at least one non-empty host
     * @param 'tls'|'ssl'|'none' $encryption
     * @param string             $fromEmail  default From for mail sent through this mailer; '' = the configured MAIL_FROM_ADDRESS
     * @param string             $fromName   default From display name; '' = the configured MAIL_FROM_NAME
     */
    public function __construct(
        array $hosts,
        int $port = 587,
        string $encryption = 'tls',
        string $username = '',
        #[\SensitiveParameter]
        string $password = '',
        string $fromEmail = '',
        string $fromName = '',
    ) {
        $hosts = array_values(array_filter(
            array_map(static fn (string $host): string => trim($host), $hosts),
            static fn (string $host): bool => $host !== '',
        ));

        if ($hosts === []) {
            throw new MailException('SmtpSettings: at least one non-empty SMTP host is required.');
        }
        if ($port < 1 || $port > 65535) {
            throw new MailException("SmtpSettings: port must be between 1 and 65535, got {$port}.");
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new MailException("SmtpSettings: unknown encryption '{$encryption}' (expected tls, ssl or none).");
        }

        $this->hosts      = $hosts;
        $this->port       = $port;
        $this->encryption = $encryption;
        $this->username   = $username;
        $this->password   = $password;
        $this->fromEmail  = trim($fromEmail);
        $this->fromName   = trim($fromName);
    }

    /** var_dump()-safe: the password is redacted, never printed in the clear. */
    public function __debugInfo(): array
    {
        return [
            'hosts'      => $this->hosts,
            'port'       => $this->port,
            'encryption' => $this->encryption,
            'username'   => $this->username,
            'password'   => $this->password === '' ? '(none)' : '••••••',
            'fromEmail'  => $this->fromEmail,
            'fromName'   => $this->fromName,
        ];
    }

    /** Log-safe summary — the password is NEVER included. */
    public function __toString(): string
    {
        return sprintf(
            'SmtpSettings(hosts: %s, port: %d, encryption: %s, username: %s)',
            implode(',', $this->hosts),
            $this->port,
            $this->encryption,
            $this->username !== '' ? $this->username : '(none)',
        );
    }
}
