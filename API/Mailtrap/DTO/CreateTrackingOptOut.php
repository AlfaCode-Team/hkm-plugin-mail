<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * Stop open/click tracking for one address on one domain.
 *
 * Distinct from a suppression: the mail is still SENT, it just carries no
 * tracking pixel or rewritten links.
 */
final readonly class CreateTrackingOptOut
{
    public string $email;

    public function __construct(string $email, public int $domainId)
    {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("CreateTrackingOptOut: invalid e-mail address: {$email}");
        }

        $this->email = $email;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['email' => $this->email, 'domain_id' => $this->domainId];
    }
}
