<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * A new webhook subscription.
 *
 * `eventTypes` and `sendingStream` are required for an `email_sending` webhook
 * and meaningless for the others — checked here rather than left to the API,
 * whose rejection says which field is missing but not why it is conditional.
 */
final readonly class CreateWebhook
{
    /** @var list<WebhookEvent> */
    public array $eventTypes;

    /** @param list<WebhookEvent> $eventTypes */
    public function __construct(
        public string $url,
        public WebhookType $webhookType,
        array $eventTypes = [],
        public ?WebhookPayloadFormat $payloadFormat = null,
        public ?SendingStream $sendingStream = null,
        public ?int $domainId = null,
        public ?bool $active = null,
        public ?int $inboundInboxId = null,
    ) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new MailException("CreateWebhook: invalid URL: {$url}");
        }

        if ($webhookType === WebhookType::EmailSending) {
            if ($eventTypes === []) {
                throw new MailException('CreateWebhook: an email_sending webhook needs at least one event type.');
            }
            if ($sendingStream === null) {
                throw new MailException('CreateWebhook: an email_sending webhook needs a sending stream.');
            }
        }

        $this->eventTypes = array_values($eventTypes);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [
            'url'          => $this->url,
            'webhook_type' => $this->webhookType->value,
            'event_types'  => array_map(static fn(WebhookEvent $e): string => $e->value, $this->eventTypes),
        ];

        if ($this->payloadFormat !== null) {
            $payload['payload_format'] = $this->payloadFormat->value;
        }
        if ($this->sendingStream !== null) {
            $payload['sending_stream'] = $this->sendingStream->value;
        }
        if ($this->domainId !== null) {
            $payload['domain_id'] = $this->domainId;
        }
        if ($this->active !== null) {
            $payload['active'] = $this->active;
        }
        if ($this->inboundInboxId !== null) {
            $payload['inbound_inbox_id'] = $this->inboundInboxId;
        }

        return $payload;
    }
}
