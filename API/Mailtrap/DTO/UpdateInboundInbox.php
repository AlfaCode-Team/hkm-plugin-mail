<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** Rename an inbound inbox. */
final readonly class UpdateInboundInbox
{
    public function __construct(public string $name)
    {
        if (trim($name) === '') {
            throw new MailException('UpdateInboundInbox: an inbox name is required.');
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}
