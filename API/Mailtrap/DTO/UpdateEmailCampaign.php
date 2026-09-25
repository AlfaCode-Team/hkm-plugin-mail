<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A partial change to a campaign — only the named fields move. */
final readonly class UpdateEmailCampaign
{
    /** @var ?list<int> */
    public ?array $contactListIds;
    /** @var ?list<int> */
    public ?array $contactSegmentIds;

    /**
     * @param ?array<string,mixed> $deliveryOptions
     * @param ?list<int>           $contactListIds
     * @param ?list<int>           $contactSegmentIds
     */
    public function __construct(
        public ?string $name = null,
        public ?int $domainId = null,
        public ?string $fromDisplayName = null,
        public ?string $fromLocalPart = null,
        public CampaignDeliveryMode|string|null $deliveryMode = null,
        public ?array $deliveryOptions = null,
        public ?CampaignReplyTo $replyTo = null,
        public ?TemplateAttributes $templateAttributes = null,
        ?array $contactListIds = null,
        ?array $contactSegmentIds = null,
    ) {
        $this->contactListIds    = $contactListIds === null ? null : array_values($contactListIds);
        $this->contactSegmentIds = $contactSegmentIds === null ? null : array_values($contactSegmentIds);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = array_filter([
            'name'                => $this->name,
            'domain_id'           => $this->domainId,
            'from_display_name'   => $this->fromDisplayName,
            'from_local_part'     => $this->fromLocalPart,
            'delivery_mode'       => $this->deliveryMode instanceof CampaignDeliveryMode
                ? $this->deliveryMode->value
                : $this->deliveryMode,
            'delivery_options'    => $this->deliveryOptions,
            'reply_to'            => $this->replyTo?->toArray(),
            'template_attributes' => $this->templateAttributes?->toArray(),
            'contact_list_ids'    => $this->contactListIds,
            'contact_segment_ids' => $this->contactSegmentIds,
        ], static fn(mixed $value): bool => $value !== null);

        if ($payload === []) {
            throw new MailException('UpdateEmailCampaign: at least one field must be set — an empty PATCH changes nothing.');
        }

        return $payload;
    }
}
