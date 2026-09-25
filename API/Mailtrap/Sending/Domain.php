<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Sending;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\UpdateDomain;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;

/**
 * Sending domains — the domains an account is allowed to send `From:`.
 *
 * Creating one does not make it usable: it returns the DNS records that must be
 * published first, and until they verify, mail from that domain is rejected.
 */
final class Domain extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getSendingDomains(): array
    {
        return $this->http->get($this->base());
    }

    /**
     * Register a domain. The response carries the DNS records to publish.
     *
     * @return array<mixed>
     */
    public function createSendingDomain(string $domainName): array
    {
        $domainName = trim($domainName);

        if ($domainName === '') {
            throw new MailException('Domain::createSendingDomain: a domain name is required.');
        }

        return $this->http->post($this->base(), ['sending_domain' => ['domain_name' => $domainName]]);
    }

    /** @return array<mixed> */
    public function getDomainById(int $domainId): array
    {
        return $this->http->get($this->base() . '/' . $domainId);
    }

    /** @return array<mixed> */
    public function updateSendingDomain(int $domainId, UpdateDomain $domain): array
    {
        return $this->http->patch($this->base() . '/' . $domainId, ['sending_domain' => $domain->toArray()]);
    }

    /** @return array<mixed> */
    public function deleteSendingDomain(int $domainId): array
    {
        return $this->http->delete($this->base() . '/' . $domainId);
    }

    /** Mail the DNS setup instructions to someone who can publish them. @return array<mixed> */
    public function sendDomainSetupInstructions(int $domainId, string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Domain::sendDomainSetupInstructions: invalid e-mail address: {$email}");
        }

        return $this->http->post($this->base() . '/' . $domainId . '/send_setup_instructions', ['email' => $email]);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/sending_domains';
    }
}
