<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A partial change to a webhook — only the named fields move. */
final readonly class UpdateWebhook
{
    /** @var ?list<WebhookEvent> */
    public ?array $eventTypes;

    /** @param ?list<WebhookEvent> $eventTypes null leaves the subscription alone; [] unsubscribes from everything */
    public function __construct(
        public ?string $url = null,
        public ?bool $active = null,
        public ?WebhookPayloadFormat $payloadFormat = null,
        ?array $eventTypes = null,
        public ?int $inboundInboxId = null,
    ) {
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new MailException("UpdateWebhook: invalid URL: {$url}");
        }

        $this->eventTypes = $eventTypes === null ? null : array_values($eventTypes);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [];

        if ($this->url !== null) {
            $payload['url'] = $this->url;
        }
        if ($this->active !== null) {
            $payload['active'] = $this->active;
        }
        if ($this->payloadFormat !== null) {
            $payload['payload_format'] = $this->payloadFormat->value;
        }
        if ($this->eventTypes !== null) {
            $payload['event_types'] = array_map(static fn(WebhookEvent $e): string => $e->value, $this->eventTypes);
        }
        if ($this->inboundInboxId !== null) {
            $payload['inbound_inbox_id'] = $this->inboundInboxId;
        }

        if ($payload === []) {
            throw new MailException('UpdateWebhook: at least one field must be set — an empty PATCH changes nothing.');
        }

        return $payload;
    }
}
