<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** Terminal state of a message in the email log. */
enum EmailLogsStatus: string
{
    case Delivered    = 'delivered';
    case NotDelivered = 'not_delivered';
    case Enqueued     = 'enqueued';
    case OptedOut     = 'opted_out';
}
