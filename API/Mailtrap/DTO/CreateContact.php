<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A new contact, with its merge fields and the lists it joins. */
final readonly class CreateContact
{
    public string $email;

    /**
     * @param array<string,mixed> $fields  merge-tag => value
     * @param list<int>           $listIds contact lists to add it to
     */
    public function __construct(
        string $email,
        public array $fields = [],
        public array $listIds = [],
    ) {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("CreateContact: invalid e-mail address: {$email}");
        }

        $this->email = $email;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'email'    => $this->email,
            // A plain array, not (object): an EMPTY map then encodes as `[]`
            // rather than `{}`. That is what the official SDK sends and what
            // the API is known to accept — casting to an object here would be
            // a deviation nothing has tested against the live endpoint.
            'fields'   => $this->fields,
            'list_ids' => array_values($this->listIds),
        ];
    }
}
