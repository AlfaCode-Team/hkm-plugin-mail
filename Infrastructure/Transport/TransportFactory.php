<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use Plugins\Mail\API\DTOs\SmtpSettings;
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
    /** @param array<string,mixed> $config the compiled `mail` config array */
    public function fromConfig(array $config): Transport
    {
        $smtp = (array) ($config['smtp'] ?? []);

        return match ((string) ($config['transport'] ?? 'smtp')) {
            'sendmail' => new SendmailTransport((string) ($config['sendmail']['binary'] ?? '/usr/sbin/sendmail')),
            'mail'     => new MailTransport(),
            'array'    => new ArrayTransport(),
            'log'      => new LogTransport(),
            default    => $this->smtpFromArray($smtp),
        };
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
