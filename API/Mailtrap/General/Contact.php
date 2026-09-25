<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\ContactExportFilter;
use Plugins\Mail\API\Mailtrap\DTO\CreateContact;
use Plugins\Mail\API\Mailtrap\DTO\CreateContactEvent;
use Plugins\Mail\API\Mailtrap\DTO\ImportContact;
use Plugins\Mail\API\Mailtrap\DTO\UpdateContact;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;

/**
 * Contacts, contact lists, contact fields, imports, events and exports.
 *
 * A contact is addressable by its ID **or** its e-mail address in the same URL
 * position, which is why every identifier goes through `segment()` — an
 * unencoded `+` in an address would otherwise become a space and address a
 * different contact, or none.
 */
final class Contact extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    // ── lists ────────────────────────────────────────────────────────────────

    /**
     * @param  ?string $search case-insensitive prefix match on the list name
     * @return array<mixed>
     */
    public function getAllContactLists(?string $search = null): array
    {
        return $this->http->get($this->base() . '/lists', $search === null ? [] : ['search' => $search]);
    }

    /** @return array<mixed> */
    public function getContactList(int $listId): array
    {
        return $this->http->get($this->base() . '/lists/' . $listId);
    }

    /** @return array<mixed> */
    public function createContactList(string $name): array
    {
        return $this->http->post($this->base() . '/lists', ['name' => $name]);
    }

    /** @return array<mixed> */
    public function updateContactList(int $listId, string $name): array
    {
        return $this->http->patch($this->base() . '/lists/' . $listId, ['name' => $name]);
    }

    /** @return array<mixed> */
    public function deleteContactList(int $listId): array
    {
        return $this->http->delete($this->base() . '/lists/' . $listId);
    }

    // ── contacts ─────────────────────────────────────────────────────────────

    /** @return array<mixed> */
    public function getContactById(string $contactId): array
    {
        return $this->http->get($this->base() . '/' . $this->segment($contactId));
    }

    /** @return array<mixed> */
    public function getContactByEmail(string $email): array
    {
        return $this->http->get($this->base() . '/' . $this->segment($email));
    }

    /** @return array<mixed> */
    public function createContact(CreateContact $contact): array
    {
        return $this->http->post($this->base(), ['contact' => $contact->toArray()]);
    }

    /** @return array<mixed> */
    public function updateContactById(string $contactId, UpdateContact $contact): array
    {
        return $this->http->patch($this->base() . '/' . $this->segment($contactId), ['contact' => $contact->toArray()]);
    }

    /** @return array<mixed> */
    public function updateContactByEmail(string $email, UpdateContact $contact): array
    {
        return $this->http->patch($this->base() . '/' . $this->segment($email), ['contact' => $contact->toArray()]);
    }

    /** @return array<mixed> */
    public function deleteContactById(string $contactId): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($contactId));
    }

    /** @return array<mixed> */
    public function deleteContactByEmail(string $email): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($email));
    }

    // ── fields ───────────────────────────────────────────────────────────────

    /** @return array<mixed> */
    public function getAllContactFields(): array
    {
        return $this->http->get($this->base() . '/fields');
    }

    /** @return array<mixed> */
    public function getContactField(int $fieldId): array
    {
        return $this->http->get($this->base() . '/fields/' . $fieldId);
    }

    /**
     * @param  string $dataType Mailtrap's field type — text, integer, float, boolean, date
     * @param  string $mergeTag the tag templates reference this field by
     * @return array<mixed>
     */
    public function createContactField(string $name, string $dataType, string $mergeTag): array
    {
        return $this->http->post($this->base() . '/fields', [
            'name'      => $name,
            'data_type' => $dataType,
            'merge_tag' => $mergeTag,
        ]);
    }

    /**
     * The data type is NOT updatable — Mailtrap accepts only the name and the
     * merge tag, because changing a field's type would reinterpret every
     * contact's stored value.
     *
     * @return array<mixed>
     */
    public function updateContactField(int $fieldId, string $name, string $mergeTag): array
    {
        return $this->http->patch($this->base() . '/fields/' . $fieldId, [
            'name'      => $name,
            'merge_tag' => $mergeTag,
        ]);
    }

    /** @return array<mixed> */
    public function deleteContactField(int $fieldId): array
    {
        return $this->http->delete($this->base() . '/fields/' . $fieldId);
    }

    // ── imports / events / exports ───────────────────────────────────────────

    /**
     * Queue a bulk import. Returns an import id — the work is ASYNCHRONOUS, so
     * poll {@see self::getContactImport()} rather than treating the response as
     * a completed import.
     *
     * @param  list<ImportContact> $contacts
     * @return array<mixed>
     */
    public function importContacts(array $contacts): array
    {
        if ($contacts === []) {
            throw new MailException('Contact::importContacts: at least one contact is required.');
        }

        return $this->http->post($this->base() . '/imports', [
            'contacts' => array_map(static fn(ImportContact $c): array => $c->toArray(), array_values($contacts)),
        ]);
    }

    /** @return array<mixed> */
    public function getContactImport(int $importId): array
    {
        return $this->http->get($this->base() . '/imports/' . $importId);
    }

    /**
     * @param  string $contactIdentifier a contact id OR an e-mail address
     * @return array<mixed>
     */
    public function createContactEvent(string $contactIdentifier, CreateContactEvent $event): array
    {
        return $this->http->post(
            $this->base() . '/' . $this->segment($contactIdentifier) . '/events',
            $event->toArray(),
        );
    }

    /**
     * Queue an export. Asynchronous like an import — poll
     * {@see self::getContactExport()} for the download.
     *
     * @param  list<ContactExportFilter> $filters an empty list exports everything
     * @return array<mixed>
     */
    public function createContactExport(array $filters = []): array
    {
        return $this->http->post($this->base() . '/exports', [
            'filters' => array_map(static fn(ContactExportFilter $f): array => $f->toArray(), array_values($filters)),
        ]);
    }

    /** @return array<mixed> */
    public function getContactExport(int $exportId): array
    {
        return $this->http->get($this->base() . '/exports/' . $exportId);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/contacts';
    }
}
