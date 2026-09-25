<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * A change to a sandbox inbox's name or e-mail username.
 *
 * At least one has to be set: the API treats an empty `inbox` object as a
 * successful no-op, so an accidental empty update would look like it worked.
 */
final readonly class SandboxInboxUpdate
{
    public function __construct(
        public ?string $name = null,
        public ?string $emailUsername = null,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        $payload = array_filter([
            'name'           => $this->name,
            'email_username' => $this->emailUsername,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        if ($payload === []) {
            throw new MailException('SandboxInboxUpdate: set a name or an email username — an empty update changes nothing.');
        }

        return $payload;
    }
}
