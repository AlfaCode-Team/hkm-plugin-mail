<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * The physical sender identity attached to a sending domain.
 *
 * Not optional paperwork: CAN-SPAM and the GDPR require a real postal address
 * in commercial mail, and Mailtrap renders this into the unsubscribe footer.
 */
final readonly class CreateCompanyInfo
{
    public function __construct(
        public string $name,
        public string $address,
        public string $city,
        public string $country,
        public string $zipCode,
        public string $websiteUrl,
        public ?string $phone = null,
        public ?string $privacyPolicyUrl = null,
        public ?string $termsOfServiceUrl = null,
        public ?CompanyInfoLevel $infoLevel = null,
    ) {
        foreach (['name' => $name, 'address' => $address, 'city' => $city, 'country' => $country] as $field => $value) {
            if (trim($value) === '') {
                throw new MailException("CreateCompanyInfo: '{$field}' is required.");
            }
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        $payload = [
            'name'        => $this->name,
            'address'     => $this->address,
            'city'        => $this->city,
            'country'     => $this->country,
            'zip_code'    => $this->zipCode,
            'website_url' => $this->websiteUrl,
        ];

        foreach ([
            'phone'                => $this->phone,
            'privacy_policy_url'   => $this->privacyPolicyUrl,
            'terms_of_service_url' => $this->termsOfServiceUrl,
        ] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        if ($this->infoLevel !== null) {
            $payload['info_level'] = $this->infoLevel->value;
        }

        return $payload;
    }
}
