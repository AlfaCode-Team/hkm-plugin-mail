<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * What a webhook is subscribed to.
 *
 * `EmailSending` is the only one that requires `eventTypes` and a
 * `sendingStream` — {@see CreateWebhook} enforces that, because the API's
 * rejection names neither.
 */
enum WebhookType: string
{
    case EmailSending     = 'email_sending';
    case AuditLog         = 'audit_log';
    case InboundReceiving = 'inbound_receiving';
}
