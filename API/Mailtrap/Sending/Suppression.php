<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateSuppression;
use Plugins\Mail\API\Mailtrap\DTO\SuppressionsFilter;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Addresses Mailtrap will not deliver to — hard bounces, unsubscribes, spam
 * complaints, manual entries.
 *
 * Sending to a suppressed address is silently dropped, so this is where a "we
 * sent it but they never got it" investigation usually ends. DELETING a
 * suppression re-enables delivery: do it only when you know why the address was
 * suppressed, because removing a spam-complaint entry and mailing again is what
 * gets a sending domain blocked.
 */
final class Suppression extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * @param  string|SuppressionsFilter|null $filter a bare string is treated as an e-mail address
     * @return array<mixed>
     */
    public function getSuppressions(string|SuppressionsFilter|null $filter = null): array
    {
        $query = match (true) {
            $filter instanceof SuppressionsFilter => $filter->toArray(),
            is_string($filter) && $filter !== ''  => ['email' => $filter],
            default                               => [],
        };

        return $this->http->get($this->base(), $query);
    }

    /** @return array<mixed> */
    public function createSuppression(CreateSuppression $suppression): array
    {
        return $this->http->post($this->base(), $suppression->toArray());
    }

    /** Re-enable delivery to a suppressed address. @return array<mixed> */
    public function deleteSuppression(string $suppressionId): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($suppressionId));
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/suppressions';
    }
}
