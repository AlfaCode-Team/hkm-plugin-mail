<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** The delivery events an `email_sending` webhook can subscribe to. */
enum WebhookEvent: string
{
    case Delivery      = 'delivery';
    case Bounce        = 'bounce';
    case SoftBounce    = 'soft_bounce';
    case Suspension    = 'suspension';
    case Unsubscribe   = 'unsubscribe';
    case Open          = 'open';
    case SpamComplaint = 'spam_complaint';
    case Click         = 'click';
    case Reject        = 'reject';
}
