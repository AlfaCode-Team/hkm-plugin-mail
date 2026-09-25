<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A new inbound folder — the container inbound inboxes live in. */
final readonly class CreateInboundFolder
{
    public function __construct(public string $name)
    {
        if (trim($name) === '') {
            throw new MailException('CreateInboundFolder: a folder name is required.');
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}
