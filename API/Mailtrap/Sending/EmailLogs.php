<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\EmailLogsListFilters;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * What actually happened to sent mail — the log a bounce investigation starts
 * from, keyed by the `message_ids` a send returned.
 */
final class EmailLogs extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * A page of the log: `{ messages, total_count, next_page_cursor }`.
     *
     * @param  EmailLogsListFilters|array<string,mixed> $filters     a built filter, or a raw array
     * @param  ?string                                  $searchAfter the previous page's `next_page_cursor`
     * @return array<mixed>
     */
    public function getList(EmailLogsListFilters|array $filters = [], ?string $searchAfter = null): array
    {
        $query = [];

        if ($searchAfter !== null && $searchAfter !== '') {
            $query['search_after'] = $searchAfter;
        }

        $filterArray = $filters instanceof EmailLogsListFilters ? $filters->toArray() : $filters;
        if ($filterArray !== []) {
            $query['filters'] = $filterArray;
        }

        return $this->http->get($this->base(), $query);
    }

    /** @param string $sendingMessageId one of the ids a send returned. @return array<mixed> */
    public function getMessage(string $sendingMessageId): array
    {
        return $this->http->get($this->base() . '/' . $this->segment($sendingMessageId));
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/email_logs';
    }
}
