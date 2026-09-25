<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\General;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\Permissions;
use Plugins\Mail\API\Mailtrap\DTO\TokenExpiration;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;

/**
 * API tokens for one account.
 *
 * The token VALUE is returned only by create and reset — it is never readable
 * again, so a caller that does not store the response has lost it.
 */
final class ApiToken extends AbstractMailtrapApi
{
    public function __construct(MailtrapHttp $http, private readonly int $accountId)
    {
        parent::__construct($http);
    }

    /** @return array<mixed> */
    public function getApiTokens(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getApiToken(int $apiTokenId): array
    {
        return $this->http->get($this->base() . '/' . $apiTokenId);
    }

    /**
     * @param ?TokenExpiration $expiration omit for the server default; see {@see TokenExpiration}
     * @return array<mixed>
     */
    public function createApiToken(string $name, Permissions $permissions, ?TokenExpiration $expiration = null): array
    {
        $body = ['name' => $name, 'resources' => $permissions->toArray()];

        if ($expiration !== null) {
            $body['expires_at'] = $expiration->value;
        }

        return $this->http->post($this->base(), $body);
    }

    /** @return array<mixed> */
    public function deleteApiToken(int $apiTokenId): array
    {
        return $this->http->delete($this->base() . '/' . $apiTokenId);
    }

    /**
     * Issue a new value for this token. The previous value stops working
     * immediately — there is no grace period.
     *
     * @return array<mixed>
     */
    public function resetApiToken(int $apiTokenId, ?TokenExpiration $expiration = null): array
    {
        $path = $this->base() . '/' . $apiTokenId . '/reset';

        return $expiration === null
            ? $this->http->post($path)
            : $this->http->post($path, ['expires_at' => $expiration->value]);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    private function base(): string
    {
        return '/api/accounts/' . $this->accountId . '/api_tokens';
    }
}
