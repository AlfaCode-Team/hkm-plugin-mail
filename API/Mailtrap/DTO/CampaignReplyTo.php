<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * A campaign's Reply-To, expressed as PARTS rather than an address.
 *
 * Campaigns are bound to a sending domain, so the API takes local part and
 * domain separately and validates the domain against the account's verified
 * ones — an address string could name a domain the account cannot send from.
 */
final readonly class CampaignReplyTo
{
    public function __construct(
        public ?string $displayName = null,
        public ?string $localPart = null,
        public ?string $domain = null,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return array_filter([
            'display_name' => $this->displayName,
            'local_part'   => $this->localPart,
            'domain'       => $this->domain,
        ], static fn(?string $value): bool => $value !== null);
    }
}
