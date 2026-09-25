<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\Permissions;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Resource permissions for a user or token on one account. */
final class Permission extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * Every resource (inboxes, projects, domains, billing, the account itself)
     * this token has ADMIN access to — the set a permission may be granted on.
     *
     * @return array<mixed>
     */
    public function getResources(): array
    {
        return $this->http->get('/api/accounts/' . $this->accountId . '/permissions/resources');
    }

    /**
     * Apply grants and revocations in one call.
     *
     * An upsert: a resource_type + resource_id pair that already has a
     * permission is updated, a new pair is created, and a
     * {@see \Plugins\Mail\API\Mailtrap\DTO\RevokePermission} removes one.
     *
     * @return array<mixed>
     */
    public function update(int $accountAccessId, Permissions $permissions): array
    {
        return $this->http->put(
            '/api/accounts/' . $this->accountId . '/account_accesses/' . $accountAccessId . '/permissions/bulk',
            ['permissions' => $permissions->toArray()],
        );
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }
}
