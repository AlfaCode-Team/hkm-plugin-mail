<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateEmailCampaign;
use Plugins\Mail\API\Mailtrap\DTO\UpdateEmailCampaign;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * Email campaigns and their lifecycle.
 *
 * NOTE the path: `/api/email_campaigns`, with NO account segment — the account
 * is implied by the token. The account id is still held here so the client is
 * addressed consistently with every other account-scoped API, and
 * {@see self::getAccountId()} can answer which account a caller is working in.
 *
 * The lifecycle verbs are not interchangeable:
 *   start      send it now
 *   schedule   send it at a future instant
 *   cancel     un-schedule a campaign that has not started
 *   terminate  stop one that IS sending — already-sent mail stays sent
 *   reset      return a finished campaign to draft
 */
final class EmailCampaign extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /**
     * @param  ?int    $perPage max 100, default 50
     * @param  ?string $search  filter by campaign name
     * @param  ?int    $token   page number (page-token pagination, default 1)
     * @return array<mixed>
     */
    public function getEmailCampaigns(?int $perPage = null, ?string $search = null, ?int $token = null): array
    {
        $query = array_filter([
            'per_page' => $perPage,
            'search'   => $search,
            'token'    => $token,
        ], static fn(mixed $value): bool => $value !== null);

        return $this->http->get($this->base(), $query);
    }

    /** @return array<mixed> */
    public function getEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->get($this->base() . '/' . $emailCampaignId);
    }

    /** @return array<mixed> */
    public function createEmailCampaign(CreateEmailCampaign $campaign): array
    {
        return $this->http->post($this->base(), $campaign->toArray());
    }

    /** @return array<mixed> */
    public function updateEmailCampaign(int $emailCampaignId, UpdateEmailCampaign $campaign): array
    {
        return $this->http->patch($this->base() . '/' . $emailCampaignId, $campaign->toArray());
    }

    /** @return array<mixed> */
    public function deleteEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->delete($this->base() . '/' . $emailCampaignId);
    }

    /** Send now. @return array<mixed> */
    public function startEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->post($this->base() . '/' . $emailCampaignId . '/start');
    }

    /**
     * Send at a future instant.
     *
     * A \DateTimeInterface is formatted as ISO 8601; a string is passed through
     * so a caller can use whatever form the API documents today.
     *
     * @return array<mixed>
     */
    public function scheduleEmailCampaign(int $emailCampaignId, string|\DateTimeInterface $datetime): array
    {
        return $this->http->post($this->base() . '/' . $emailCampaignId . '/schedule', [
            'datetime' => $datetime instanceof \DateTimeInterface
                ? $datetime->format(\DateTimeInterface::ATOM)
                : $datetime,
        ]);
    }

    /** Un-schedule a campaign that has not started. @return array<mixed> */
    public function cancelEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->post($this->base() . '/' . $emailCampaignId . '/cancel');
    }

    /** Stop a campaign that IS sending. Already-delivered mail stays delivered. @return array<mixed> */
    public function terminateEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->post($this->base() . '/' . $emailCampaignId . '/terminate');
    }

    /** Return a finished campaign to draft. @return array<mixed> */
    public function resetEmailCampaign(int $emailCampaignId): array
    {
        return $this->http->post($this->base() . '/' . $emailCampaignId . '/reset');
    }

    /**
     * Aggregated performance, wrapped in `data`. Defaults to the whole period
     * since the campaign was last started.
     *
     * @param  ?string $startDate inclusive, YYYY-MM-DD
     * @param  ?string $endDate   inclusive, YYYY-MM-DD
     * @return array<mixed>
     */
    public function getEmailCampaignStats(int $emailCampaignId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = array_filter([
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ], static fn(?string $value): bool => $value !== null);

        return $this->http->get($this->base() . '/' . $emailCampaignId . '/stats', $query);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/email_campaigns';
    }
}
