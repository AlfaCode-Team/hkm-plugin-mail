<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * Changes to an existing contact.
 *
 * List membership is a DELTA (`included`/`excluded`), not a replacement — the
 * API applies the two sets rather than overwriting what the contact is in.
 * `unsubscribed` is nullable because `false` (resubscribe) and "leave it alone"
 * are different requests.
 */
final readonly class UpdateContact
{
    public string $email;

    /**
     * @param array<string,mixed> $fields          merge-tag => value
     * @param list<int>           $listIdsIncluded lists to ADD the contact to
     * @param list<int>           $listIdsExcluded lists to REMOVE it from
     */
    public function __construct(
        string $email,
        public array $fields = [],
        public array $listIdsIncluded = [],
        public array $listIdsExcluded = [],
        public ?bool $unsubscribed = null,
    ) {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("UpdateContact: invalid e-mail address: {$email}");
        }

        $this->email = $email;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [
            'email'             => $this->email,
            // A plain array, not (object): an EMPTY map then encodes as `[]`
            // rather than `{}`. That is what the official SDK sends and what
            // the API is known to accept — casting to an object here would be
            // a deviation nothing has tested against the live endpoint.
            'fields'            => $this->fields,
            'list_ids_included' => array_values($this->listIdsIncluded),
            'list_ids_excluded' => array_values($this->listIdsExcluded),
        ];

        if ($this->unsubscribed !== null) {
            $payload['unsubscribed'] = $this->unsubscribed;
        }

        return $payload;
    }
}
