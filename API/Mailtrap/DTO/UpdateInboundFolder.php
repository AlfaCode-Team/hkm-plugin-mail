<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** Rename an inbound folder. */
final readonly class UpdateInboundFolder
{
    public function __construct(public string $name)
    {
        if (trim($name) === '') {
            throw new MailException('UpdateInboundFolder: a folder name is required.');
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}
