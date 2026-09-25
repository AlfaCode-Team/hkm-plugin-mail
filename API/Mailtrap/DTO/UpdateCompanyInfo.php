<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A partial change to a sending domain's company info — only the named fields move. */
final readonly class UpdateCompanyInfo
{
    public function __construct(
        public ?string $name = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $zipCode = null,
        public ?string $websiteUrl = null,
        public ?string $phone = null,
        public ?string $privacyPolicyUrl = null,
        public ?string $termsOfServiceUrl = null,
        public ?CompanyInfoLevel $infoLevel = null,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        $payload = array_filter([
            'name'                 => $this->name,
            'address'              => $this->address,
            'city'                 => $this->city,
            'country'              => $this->country,
            'zip_code'             => $this->zipCode,
            'website_url'          => $this->websiteUrl,
            'phone'                => $this->phone,
            'privacy_policy_url'   => $this->privacyPolicyUrl,
            'terms_of_service_url' => $this->termsOfServiceUrl,
        ], static fn(?string $value): bool => $value !== null);

        if ($this->infoLevel !== null) {
            $payload['info_level'] = $this->infoLevel->value;
        }

        if ($payload === []) {
            throw new MailException('UpdateCompanyInfo: at least one field must be set — an empty PATCH changes nothing.');
        }

        return $payload;
    }
}
