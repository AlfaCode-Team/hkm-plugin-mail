<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * What kind of thing a permission is granted over.
 *
 * An enum rather than a bare string because this value is an INPUT that the API
 * validates against a closed set, and a typo is silent in the worst way: the
 * grant does not take effect, and the account keeps whatever access it had. The
 * other closed vocabularies here ({@see WebhookType}, {@see SendingStream},
 * {@see SuppressionType}) are enums for the same reason.
 *
 * `MailsendDomain` is the sending domain, spelled `mailsend_domain` by the API
 * — not `sending_domain`, which is what the Sending Domains endpoints use for
 * the same object.
 */
enum PermissionResourceType: string
{
    case Account        = 'account';
    case Billing        = 'billing';
    case Project        = 'project';
    case Inbox          = 'inbox';
    case MailsendDomain = 'mailsend_domain';
}
