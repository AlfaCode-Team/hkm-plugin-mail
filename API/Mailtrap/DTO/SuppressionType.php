<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** Why an address is on the suppression list. */
enum SuppressionType: string
{
    case HardBounce    = 'hard bounce';
    case Unsubscription = 'unsubscription';
    case SpamComplaint = 'spam complaint';
    case ManualImport  = 'manual import';
}
