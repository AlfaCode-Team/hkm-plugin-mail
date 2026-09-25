<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\DTOs\MailtrapStream;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\API\Mailtrap\MailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Infrastructure\Security\DkimSigner;

/**
 * Builds a `Transport` (and its `DkimSigner`) from the plugin's `mail` config
 * array — the ONE place this logic lives, so the env-driven mailer
 * ({@see \Plugins\Mail\Provider}) and a caller-supplied-SMTP mailer
 * ({@see \Plugins\Mail\Application\MailerFactory}) cannot drift apart.
 *
 * `fromConfig()` behaves byte-for-byte like the transport selection this
 * plugin has always done (`smtp`|`sendmail`|`mail`|`array`|`log`).
 * `smtpFromSettings()` builds an SMTP transport for OTHER settings than the
 * configured default (e.g. a tenant's own server): the hosts/port/encryption/
 * username/password come from the caller, every other tunable (auth mode,
 * OAuth token, HELO domain, timeout, peer verification, keep-alive,
 * insecure-auth) still comes from the plugin's OWN configured `mail.smtp`.
 *
 * Deliberately NOT `final` (the one exception in this plugin): it is the seam
 * a test substitutes to give {@see \Plugins\Mail\Application\MailerFactory} a
 * fake `Transport` instead of dialling real SMTP — see
 * `tests/MailerFactoryTest.php`.
 */
class TransportFactory
{
    /**
     * Outbound HTTP for API-backed transports.
     *
     * Optional because the SMTP/sendmail/mail/array/log transports need none,
     * and the Mail plugin declares no `requires[]` — an application that never
     * sets MAIL_TRANSPORT=mailtrap must not be forced to install the HttpClient
     * plugin. When it IS needed and absent, the failure names the fix; see
     * {@see self::requireHttpClient()}.
     *
     * Declared with a default and assigned in the body rather than promoted,
     * so a subclass that overrides the constructor WITHOUT calling parent's
     * (the test stubs do exactly that) leaves this null and gets that same
     * descriptive failure, instead of an "accessed before initialization" Error.
     */
    private ?HttpClientPort $http = null;

    public function __construct(?HttpClientPort $http = null)
    {
        $this->http = $http;
    }

    /** @param array<string,mixed> $config the compiled `mail` config array */
    public function fromConfig(array $config): Transport
    {
        $smtp = (array) ($config['smtp'] ?? []);
        $name = strtolower(trim((string) ($config['transport'] ?? '')));

        return match ($name) {
            // Unset or blank is the documented default, and stays SMTP.
            '', 'smtp' => $this->smtpFromArray($smtp),
            'sendmail' => new SendmailTransport((string) ($config['sendmail']['binary'] ?? '/usr/sbin/sendmail')),
            'mail'     => new MailTransport(),
            'array'    => new ArrayTransport(),
            'log'      => new LogTransport(),
            'mailtrap' => $this->mailtrapFromArray((array) ($config['mailtrap'] ?? [])),
            // A name this does not recognise used to fall through to SMTP, which
            // is the worst available outcome: `MAIL_TRANSPORT=mailtrp` built an
            // SMTP transport against whatever MAIL_HOST defaulted to, so the
            // misconfiguration surfaced — if at all — as a connection error
            // naming a host the operator never chose, long after the typo.
            default => throw new MailException(sprintf(
                "Unknown MAIL_TRANSPORT '%s' (expected one of: smtp, sendmail, mail, mailtrap, array, log).",
                $name,
            )),
        };
    }

    /**
     * An API transport for a Mailtrap account THIS module's own config knows
     * nothing about — a tenant's own account, or a per-feature sandbox inbox.
     */
    public function mailtrapFromSettings(MailtrapSettings $settings): MessageTransport
    {
        return new MailtrapTransport($this->requireHttpClient(), $settings);
    }

    /**
     * @param array<string,mixed> $smtpConfig the configured `mail.smtp` array —
     *                                          supplies every tunable OTHER than
     *                                          hosts/port/encryption/username/password
     */
    public function smtpFromSettings(SmtpSettings $settings, array $smtpConfig): Transport
    {
        return $this->smtpFromArray(array_merge($smtpConfig, [
            'hosts'      => $settings->hosts,
            'port'       => $settings->port,
            'encryption' => $settings->encryption,
            'username'   => $settings->username,
            'password'   => $settings->password,
        ]));
    }

    /** @param array<string,mixed> $config the compiled `mail` config array */
    public function dkim(array $config): ?DkimSigner
    {
        $dkim     = (array) ($config['dkim'] ?? []);
        $domain   = (string) ($dkim['domain'] ?? '');
        $selector = (string) ($dkim['selector'] ?? '');
        $key      = (string) ($dkim['private_key'] ?? '');

        if ($domain === '' || $selector === '' || $key === '') {
            return null;
        }
        if (is_file($key) && is_readable($key)) {
            $key = (string) file_get_contents($key);
        }

        return new DkimSigner($domain, $selector, $key);
    }

    /**
     * The Mailtrap MANAGEMENT API for credentials the caller supplies.
     *
     * Note the HOST: management lives on `mailtrap.io`, not on the stream's
     * sending host, so the settings' stream is deliberately ignored here. A
     * `hostOverride` is still honoured — that is how a test or a proxy redirects
     * both surfaces at once.
     */
    public function mailtrapApiFromSettings(MailtrapSettings $settings): MailtrapApi
    {
        return new MailtrapApi(new MailtrapHttp(
            $this->requireHttpClient(),
            $settings->apiToken,
            $settings->hostOverride !== '' ? $settings->hostOverride : MailtrapHttp::DEFAULT_HOST,
            $settings->timeout,
        ));
    }

    /**
     * The Mailtrap management API from the plugin's own `mail.mailtrap` config.
     *
     * Usable even when MAIL_TRANSPORT is something else entirely: an
     * application can deliver over SMTP and still read its bounce log, as long
     * as MAILTRAP_API_TOKEN is set.
     *
     * @param array<string,mixed> $mailtrap
     */
    public function mailtrapApiFromArray(array $mailtrap): MailtrapApi
    {
        return new MailtrapApi(new MailtrapHttp(
            $this->requireHttpClient(),
            (string) ($mailtrap['token'] ?? ''),
            (string) ($mailtrap['host'] ?? '') !== '' ? (string) $mailtrap['host'] : MailtrapHttp::DEFAULT_HOST,
            (int) ($mailtrap['timeout'] ?? 30),
        ));
    }

    /**
     * Build the configured Mailtrap transport from `mail.mailtrap`.
     *
     * @param array<string,mixed> $mailtrap
     */
    private function mailtrapFromArray(array $mailtrap): MailtrapTransport
    {
        $inbox = (int) ($mailtrap['inbox_id'] ?? 0);

        return new MailtrapTransport($this->requireHttpClient(), new MailtrapSettings(
            apiToken:     (string) ($mailtrap['token'] ?? ''),
            stream:       MailtrapStream::parse((string) ($mailtrap['stream'] ?? 'transactional')),
            inboxId:      $inbox > 0 ? $inbox : null,
            hostOverride: (string) ($mailtrap['host'] ?? ''),
            timeout:      (int) ($mailtrap['timeout'] ?? 30),
        ));
    }

    /**
     * The HttpClientPort, or a failure that says how to get one.
     *
     * This is the one dependency the Mail plugin cannot satisfy itself. It is
     * resolved optionally (see the constructor) so SMTP users pay nothing for
     * it, which means the absence has to be reported HERE, at the moment a
     * mailtrap transport is built — early enough that it is a boot/first-send
     * failure with a fix in it, rather than a null dereference deep in a send.
     */
    private function requireHttpClient(): HttpClientPort
    {
        if ($this->http === null) {
            throw new MailException(
                'The Mailtrap transport needs an HttpClientPort, and none is bound. '
                . 'Install the HttpClient plugin and put it in this request\'s dependency graph — '
                . 'add "http.client" to the consuming module\'s (or route\'s) requires[], list it in '
                . 'proj.json "essentials", or bind HttpClientPort in the bootstrap\'s withPorts([...]).',
            );
        }

        return $this->http;
    }

    /**
     * @param array<string,mixed> $smtp 'hosts' is EITHER a comma-separated string
     *                                   (as it comes out of config/mail.php) OR
     *                                   an already-split list (as SmtpSettings
     *                                   supplies it) — both are accepted so this
     *                                   is the one place either caller needs.
     */
    private function smtpFromArray(array $smtp): SmtpTransport
    {
        $hosts    = $smtp['hosts'] ?? 'localhost';
        $hostList = is_array($hosts) ? $hosts : explode(',', (string) $hosts);

        return new SmtpTransport(
            hosts:      array_values(array_filter(array_map('trim', $hostList))),
            port:       (int) ($smtp['port'] ?? 587),
            encryption: (string) ($smtp['encryption'] ?? 'tls'),
            username:   (string) ($smtp['username'] ?? ''),
            password:   (string) ($smtp['password'] ?? ''),
            authMode:   (string) ($smtp['auth_mode'] ?? 'auto'),
            oauthToken: (string) ($smtp['oauth_token'] ?? ''),
            heloDomain: (string) ($smtp['helo_domain'] ?? ''),
            timeout:    (int) ($smtp['timeout'] ?? 30),
            verifyPeer: (bool) ($smtp['verify_peer'] ?? true),
            keepAlive:  (bool) ($smtp['keep_alive'] ?? false),
            allowInsecureAuth: (bool) ($smtp['allow_insecure_auth'] ?? false),
        );
    }
}
