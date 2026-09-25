<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateCompanyInfo;
use Plugins\Mail\API\Mailtrap\DTO\UpdateCompanyInfo;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * The physical sender identity on one sending DOMAIN (not an account) — the
 * postal address CAN-SPAM and the GDPR require in commercial mail, which
 * Mailtrap renders into the unsubscribe footer.
 */
final class CompanyInfo extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $domainId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getCompanyInfo(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function createCompanyInfo(CreateCompanyInfo $companyInfo): array
    {
        return $this->http->post($this->base(), ['company_info' => $companyInfo->toArray()]);
    }

    /** @return array<mixed> */
    public function updateCompanyInfo(UpdateCompanyInfo $companyInfo): array
    {
        return $this->http->patch($this->base(), ['company_info' => $companyInfo->toArray()]);
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    private function base(): string
    {
        return '/api/domains/' . $this->domainId . '/company_info';
    }
}
