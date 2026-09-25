<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Sub-accounts within an organization. */
final class SubAccount extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $organizationId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getSubAccounts(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function createSubAccount(string $name): array
    {
        return $this->http->post($this->base(), ['account' => ['name' => $name]]);
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    private function base(): string
    {
        return '/api/organizations/' . $this->organizationId . '/sub_accounts';
    }
}
