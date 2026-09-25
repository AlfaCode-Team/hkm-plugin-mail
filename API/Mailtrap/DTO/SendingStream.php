<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * Which sending stream a suppression, webhook or statistic applies to.
 *
 * Distinct from {@see \Plugins\Mail\API\DTOs\MailtrapStream}, which also carries
 * `sandbox` and resolves to a HOST. Here there are only the two real streams,
 * because nothing is ever suppressed or reported on for a sandbox inbox.
 */
enum SendingStream: string
{
    case Transactional = 'transactional';
    case Bulk          = 'bulk';
}
