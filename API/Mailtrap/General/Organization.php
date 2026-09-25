<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * An organization — the scope sub-accounts live in.
 *
 * Has no endpoints of its own; it exists so an organization id is captured once
 * and the resources under it are reached from there.
 */
final class Organization extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $organizationId)
    {
        parent::__construct($http);
    }

    public function subAccounts(): SubAccount
    {
        return new SubAccount($this->http, $this->organizationId);
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }
}
