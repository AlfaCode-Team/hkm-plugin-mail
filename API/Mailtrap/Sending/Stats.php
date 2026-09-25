<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Aggregated sending statistics, grouped five ways.
 *
 * Every method takes the same window and the same optional narrowing; only the
 * grouping differs, so they share {@see self::query()}.
 */
final class Stats extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * Totals for the window.
     *
     * @param  string     $startDate             YYYY-MM-DD, inclusive
     * @param  string     $endDate               YYYY-MM-DD, inclusive
     * @param  list<int>  $sendingDomainIds
     * @param  list<string> $sendingStreams      'transactional' / 'bulk'
     * @param  list<string> $categories
     * @param  list<string> $emailServiceProviders
     * @return array<mixed>
     */
    public function get(
        string $startDate,
        string $endDate,
        array $sendingDomainIds = [],
        array $sendingStreams = [],
        array $categories = [],
        array $emailServiceProviders = [],
    ): array {
        return $this->http->get(
            $this->base(),
            $this->query($startDate, $endDate, $sendingDomainIds, $sendingStreams, $categories, $emailServiceProviders),
        );
    }

    /** Grouped by sending domain. @return array<mixed> */
    public function byDomain(
        string $startDate,
        string $endDate,
        array $sendingDomainIds = [],
        array $sendingStreams = [],
        array $categories = [],
        array $emailServiceProviders = [],
    ): array {
        return $this->http->get(
            $this->base() . '/domains',
            $this->query($startDate, $endDate, $sendingDomainIds, $sendingStreams, $categories, $emailServiceProviders),
        );
    }

    /** Grouped by the `category` set on each message. @return array<mixed> */
    public function byCategory(
        string $startDate,
        string $endDate,
        array $sendingDomainIds = [],
        array $sendingStreams = [],
        array $categories = [],
        array $emailServiceProviders = [],
    ): array {
        return $this->http->get(
            $this->base() . '/categories',
            $this->query($startDate, $endDate, $sendingDomainIds, $sendingStreams, $categories, $emailServiceProviders),
        );
    }

    /** Grouped by recipient mailbox provider (Gmail, Outlook, …). @return array<mixed> */
    public function byEmailServiceProvider(
        string $startDate,
        string $endDate,
        array $sendingDomainIds = [],
        array $sendingStreams = [],
        array $categories = [],
        array $emailServiceProviders = [],
    ): array {
        return $this->http->get(
            $this->base() . '/email_service_providers',
            $this->query($startDate, $endDate, $sendingDomainIds, $sendingStreams, $categories, $emailServiceProviders),
        );
    }

    /**
     * A time series over the window.
     *
     * The path is `/date`, singular — the one endpoint in this group whose
     * segment does not match its method name.
     *
     * @return array<mixed>
     */
    public function byDate(
        string $startDate,
        string $endDate,
        array $sendingDomainIds = [],
        array $sendingStreams = [],
        array $categories = [],
        array $emailServiceProviders = [],
    ): array {
        return $this->http->get(
            $this->base() . '/date',
            $this->query($startDate, $endDate, $sendingDomainIds, $sendingStreams, $categories, $emailServiceProviders),
        );
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    /** @return array<string,mixed> */
    private function query(
        string $startDate,
        string $endDate,
        array $sendingDomainIds,
        array $sendingStreams,
        array $categories,
        array $emailServiceProviders,
    ): array {
        $query = ['start_date' => $startDate, 'end_date' => $endDate];

        foreach ([
            'sending_domain_ids'      => $sendingDomainIds,
            'sending_streams'         => $sendingStreams,
            'categories'              => $categories,
            'email_service_providers' => $emailServiceProviders,
        ] as $key => $values) {
            if ($values !== []) {
                $query[$key] = array_values($values);
            }
        }

        return $query;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/stats';
    }
}
