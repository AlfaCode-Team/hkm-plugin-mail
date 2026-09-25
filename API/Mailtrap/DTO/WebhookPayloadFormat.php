<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** How Mailtrap serialises a webhook batch. */
enum WebhookPayloadFormat: string
{
    case Json      = 'json';
    case JsonLines = 'jsonlines';
}
