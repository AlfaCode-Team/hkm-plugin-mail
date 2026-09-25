<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** One row of a bulk contact import. */
final readonly class ImportContact
{
    public string $email;

    /**
     * @param array<string,mixed> $fields
     * @param list<int>           $listIdsIncluded
     * @param list<int>           $listIdsExcluded
     */
    public function __construct(
        string $email,
        public array $fields = [],
        public array $listIdsIncluded = [],
        public array $listIdsExcluded = [],
    ) {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("ImportContact: invalid e-mail address: {$email}");
        }

        $this->email = $email;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'email'             => $this->email,
            // A plain array, not (object): an EMPTY map then encodes as `[]`
            // rather than `{}`. That is what the official SDK sends and what
            // the API is known to accept — casting to an object here would be
            // a deviation nothing has tested against the live endpoint.
            'fields'            => $this->fields,
            'list_ids_included' => array_values($this->listIdsIncluded),
            'list_ids_excluded' => array_values($this->listIdsExcluded),
        ];
    }
}
