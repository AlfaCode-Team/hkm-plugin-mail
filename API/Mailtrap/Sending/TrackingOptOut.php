<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateTrackingOptOut;
use Plugins\Mail\API\Mailtrap\DTO\TrackingOptOutsFilter;

/**
 * Addresses excluded from open and click tracking.
 *
 * Not a suppression: the mail is still delivered, it just carries no tracking
 * pixel and no rewritten links. This is the mechanism for honouring a "do not
 * track me" request without cutting someone off from their receipts.
 *
 * The path is account-less — `/api/tracking_opt_outs` — because the token's
 * account is implied and the entries are keyed by sending domain.
 */
final class TrackingOptOut extends AbstractMailtrapApi
{
    /** @return array<mixed> */
    public function getTrackingOptOuts(?TrackingOptOutsFilter $filter = null): array
    {
        return $this->http->get($this->base(), $filter?->toArray() ?? []);
    }

    /** @return array<mixed> */
    public function createTrackingOptOut(CreateTrackingOptOut $trackingOptOut): array
    {
        return $this->http->post($this->base(), $trackingOptOut->toArray());
    }

    /** @return array<mixed> */
    public function deleteTrackingOptOut(string $trackingOptOutId): array
    {
        return $this->http->delete($this->base() . '/' . $this->segment($trackingOptOutId));
    }

    private function base(): string
    {
        return '/api/tracking_opt_outs';
    }
}
