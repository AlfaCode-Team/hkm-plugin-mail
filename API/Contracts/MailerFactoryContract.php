<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Contracts;

use Plugins\Mail\API\DTOs\SmtpSettings;

/**
 * Builds a {@see MailerContract} for SMTP settings supplied by the CALLER
 * instead of this plugin's env-driven `mail.*` config — the published seam a
 * consuming project uses to send through, say, a tenant's own SMTP server
 * (host/login stored per tenant) while falling back to the configured mailer
 * (the `MailPort` / `MailerContract` bindings) for everything else.
 *
 * Internals this plugin builds the default mailer from (`Transport`,
 * `SmtpTransport`, `MimeBuilder`) stay `bindInternal` — importing them from a
 * project would violate GDA. This contract is the only public door to a
 * differently-configured mailer.
 */
interface MailerFactoryContract
{
    /**
     * A mailer that delivers through $settings.
     *
     * Delivery is always INLINE, never queued: the returned mailer has no
     * `QueuePort`, so `dispatch()` sends immediately and `enqueue()`/`queue()`
     * also deliver inline rather than being silently dropped on a queue no
     * worker is listening to on the tenant's behalf.
     */
    public function forSmtp(SmtpSettings $settings): MailerContract;
}
