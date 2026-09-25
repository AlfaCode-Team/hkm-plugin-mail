<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * Add an address to a domain's suppression list.
 *
 * Suppression is per (domain, stream): an address blocked for bulk mail can
 * still receive transactional mail, which is why neither field has a default.
 */
final readonly class CreateSuppression
{
    public string $email;

    public function __construct(
        string $email,
        public int $domainId,
        public SendingStream $sendingStream,
        public ?SuppressionType $type = null,
    ) {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("CreateSuppression: invalid e-mail address: {$email}");
        }

        $this->email = $email;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [
            'email'          => $this->email,
            'domain_id'      => $this->domainId,
            'sending_stream' => $this->sendingStream->value,
        ];

        if ($this->type !== null) {
            $payload['type'] = $this->type->value;
        }

        return $payload;
    }
}
