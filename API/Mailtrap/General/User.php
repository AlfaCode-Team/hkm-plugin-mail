<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Account users, addressed by their ACCOUNT ACCESS id rather than a user id —
 * the same person can hold access to several accounts, and this endpoint
 * manages one of those grants, not the person.
 *
 * Requires account admin or owner permissions.
 */
final class User extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * @param  list<int> $inboxIds   narrow to users with access to these inboxes
     * @param  list<int> $projectIds narrow to users with access to these projects
     * @return array<mixed>
     */
    public function getList(array $inboxIds = [], array $projectIds = []): array
    {
        $query = [];

        if ($inboxIds !== []) {
            $query['inbox_ids'] = array_values($inboxIds);
        }
        if ($projectIds !== []) {
            $query['project_ids'] = array_values($projectIds);
        }

        return $this->http->get('/api/accounts/' . $this->accountId . '/account_accesses', $query);
    }

    /** @return array<mixed> */
    public function delete(int $accountAccessId): array
    {
        return $this->http->delete('/api/accounts/' . $this->accountId . '/account_accesses/' . $accountAccessId);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }
}
