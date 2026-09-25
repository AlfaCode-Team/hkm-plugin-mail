<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap;

/**
 * Base for every Mailtrap management API client.
 *
 * Holds nothing but the shared {@see MailtrapHttp}. Each subclass owns its path
 * building, because the paths are not regular: some are account-scoped, some
 * organization-scoped, some domain-scoped, and campaigns are scoped to none of
 * them despite needing an account id to reach.
 */
abstract class AbstractMailtrapApi
{
    public function __construct(protected readonly MailtrapHttp $http) {}

    /** Percent-encode one path segment — a contact id can be an e-mail address. */
    final protected function segment(string|int $value): string
    {
        return rawurlencode((string) $value);
    }
}
