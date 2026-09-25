<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Contracts;

use Plugins\Mail\API\DTOs\MailtrapSettings;
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

    /**
     * A mailer that delivers through the Mailtrap Sending API under $settings.
     *
     * The same seam as {@see self::forSmtp()}, for the other kind of per-tenant
     * credential: an API token and a stream rather than a host and a login. Use
     * it for a tenant's own Mailtrap account, or to route one feature's mail
     * into a sandbox inbox while the rest of the application sends for real.
     *
     * Delivery is INLINE for the same reason it is on forSmtp(): the returned
     * mailer has no QueuePort, because a queue worker has no way to know which
     * tenant's token a job was meant to use.
     *
     * Requires an HttpClientPort in the request's dependency graph — this
     * plugin declares no `requires[]`, so the HttpClient plugin has to reach it
     * some other way (the consuming module's requires[], proj.json essentials,
     * or a withPorts() binding). Without one the call throws with that list in
     * the message.
     */
    public function forMailtrap(MailtrapSettings $settings): MailerContract;

    /**
     * The Mailtrap MANAGEMENT API for a caller-supplied token.
     *
     * The same seam again, for the surface that is not delivery: a tenant's own
     * suppression list, its sending domains, its bounce log. The configured
     * account's API is available as {@see MailtrapApiContract} straight from the
     * container; this is for the accounts that are not it.
     *
     * The settings' STREAM is ignored — management lives on `mailtrap.io`, not
     * on a sending host — but a `hostOverride` still applies.
     */
    public function forMailtrapApi(MailtrapSettings $settings): MailtrapApiContract;
}
