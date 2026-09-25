<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A behavioural event recorded against a contact (for segmentation). */
final readonly class CreateContactEvent
{
    public string $name;

    /** @param array<string,mixed> $params */
    public function __construct(string $name, public array $params = [])
    {
        $name = trim($name);

        if ($name === '') {
            throw new MailException('CreateContactEvent: an event name is required.');
        }

        $this->name = $name;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        // Plain array, matching the SDK — see CreateContact::toArray().
        return ['name' => $this->name, 'params' => $this->params];
    }
}
