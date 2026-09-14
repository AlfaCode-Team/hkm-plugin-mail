<?php

declare(strict_types=1);

namespace Plugins\Mail\Application;

use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\API\Contracts\MailerFactoryContract;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\TransportFactory;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * Builds a fully independent {@see MailerContract} for SMTP settings that do
 * NOT come from this plugin's env-driven config — a tenant's own outbound mail
 * server, most commonly, stored per tenant rather than in `mail.smtp`.
 *
 * Every OTHER transport tunable, the charset and DKIM still come from the
 * plugin's configured `mail.*`, read through the SAME {@see TransportFactory}
 * the env-driven `Mailer` binding uses — so the two paths cannot drift.
 *
 * The returned `Mailer` has NO `QueuePort`: `dispatch()` sends immediately and
 * `enqueue()`/`queue()` also deliver inline (see {@see Mailer::enqueue()} —
 * sending inline with no queue bound is existing, tested behaviour, not new
 * here). A tenant SMTP send must never be silently handed to a queue worker
 * that has no idea which tenant's credentials to use. For the same reason no
 * urgent-mail cache/slots are wired in — that guard exists to bound *queued*
 * concurrency, which does not apply when nothing is ever queued.
 */
final class MailerFactory implements MailerFactoryContract
{
    /** @param array<string,mixed> $config the compiled `mail` config array */
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly array $config,
        private readonly ?ViewRendererContract $views = null,
    ) {}

    public function forSmtp(SmtpSettings $settings): MailerContract
    {
        $fromEmail = $settings->fromEmail !== ''
            ? $settings->fromEmail
            : (string) ($this->config['from']['address'] ?? '');
        $fromName = $settings->fromName !== ''
            ? $settings->fromName
            : (string) ($this->config['from']['name'] ?? '');

        return new Mailer(
            transport: $this->transports->smtpFromSettings($settings, (array) ($this->config['smtp'] ?? [])),
            mime:      new MimeBuilder(),
            dkim:      $this->transports->dkim($this->config),
            views:     $this->views,
            queue:     null,
            fromEmail: $fromEmail,
            fromName:  $fromName,
            charset:   (string) ($this->config['charset'] ?? 'UTF-8'),
        );
    }
}
