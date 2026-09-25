<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * A new campaign.
 *
 * The sender is `fromLocalPart` + `domainId`, never a full address — the domain
 * must be one the account has verified, and the API resolves it from the id.
 */
final readonly class CreateEmailCampaign
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
        public string $name,
        public int $domainId,
        public string $fromLocalPart,
        public TemplateAttributes $templateAttributes,
        public ?string $fromDisplayName = null,
        public ?CampaignReplyTo $replyTo = null,
        public CampaignDeliveryMode|string|null $deliveryMode = null,
        public ?array $deliveryOptions = null,
        ?array $contactListIds = null,
        ?array $contactSegmentIds = null,
    ) {
        if (trim($name) === '') {
            throw new MailException('CreateEmailCampaign: a campaign name is required.');
        }
        if (trim($fromLocalPart) === '') {
            throw new MailException('CreateEmailCampaign: a from local part is required.');
        }

        $this->contactListIds    = $contactListIds === null ? null : array_values($contactListIds);
        $this->contactSegmentIds = $contactSegmentIds === null ? null : array_values($contactSegmentIds);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name'                => $this->name,
            'domain_id'           => $this->domainId,
            'from_local_part'     => $this->fromLocalPart,
            'from_display_name'   => $this->fromDisplayName,
            'reply_to'            => $this->replyTo?->toArray(),
            'template_attributes' => $this->templateAttributes->toArray(),
            'delivery_mode'       => $this->deliveryMode instanceof CampaignDeliveryMode
                ? $this->deliveryMode->value
                : $this->deliveryMode,
            'delivery_options'    => $this->deliveryOptions,
            'contact_list_ids'    => $this->contactListIds,
            'contact_segment_ids' => $this->contactSegmentIds,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
