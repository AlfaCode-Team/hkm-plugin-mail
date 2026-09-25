<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * Tracking and inbound switches on a sending domain.
 *
 * Every field is nullable and omitted when null, because this is a PATCH: a
 * `false` turns a feature OFF and `null` leaves it as it is. Sending the whole
 * struct with defaults would silently disable whatever the caller did not name.
 */
final readonly class UpdateDomain
{
    public function __construct(
        public ?bool $openTrackingEnabled = null,
        public ?bool $clickTrackingEnabled = null,
        public ?bool $trackingOptOutEnabled = null,
        public ?bool $autoUnsubscribeLinkEnabled = null,
        public ?bool $inboundEnabled = null,
    ) {}

    /** @return array<string,bool> */
    public function toArray(): array
    {
        $payload = array_filter([
            'open_tracking_enabled'         => $this->openTrackingEnabled,
            'click_tracking_enabled'        => $this->clickTrackingEnabled,
            'tracking_opt_out_enabled'      => $this->trackingOptOutEnabled,
            'auto_unsubscribe_link_enabled' => $this->autoUnsubscribeLinkEnabled,
            'inbound_enabled'               => $this->inboundEnabled,
        ], static fn(?bool $value): bool => $value !== null);

        if ($payload === []) {
            throw new MailException('UpdateDomain: at least one field must be set — an empty PATCH changes nothing.');
        }

        return $payload;
    }
}
