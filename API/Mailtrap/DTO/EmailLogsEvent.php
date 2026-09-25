<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** An event recorded against a message in the email log. */
enum EmailLogsEvent: string
{
    case Delivery    = 'delivery';
    case Open        = 'open';
    case Click       = 'click';
    case Bounce      = 'bounce';
    case Spam        = 'spam';
    case Unsubscribe = 'unsubscribe';
    case SoftBounce  = 'soft_bounce';
    case Reject      = 'reject';
    case Suspension  = 'suspension';
}
