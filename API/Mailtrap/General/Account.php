<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;

/** Accounts the API token can reach. The entry point for every account id. */
final class Account extends AbstractMailtrapApi
{
    /** @return array<mixed> */
    public function getList(): array
    {
        return $this->http->get('/api/accounts');
    }
}
