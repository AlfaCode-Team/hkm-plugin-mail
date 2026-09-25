<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * How fast a campaign is released to its recipients.
 *
 * `Gradual` spreads the send over time, which is what protects a domain's
 * reputation on a large list; `Rapid` sends as fast as the account allows.
 */
enum CampaignDeliveryMode: string
{
    case Rapid   = 'rapid';
    case Gradual = 'gradual';
}
