<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/** Usage for the current billing cycle, for Email Testing and Email Sending. */
final class Billing extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getBillingUsage(): array
    {
        return $this->http->get('/api/accounts/' . $this->accountId . '/billing/usage');
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }
}
