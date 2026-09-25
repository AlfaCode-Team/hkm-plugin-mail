<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * When a newly created or reset API token stops working.
 *
 * Three states, and they are genuinely different — which is why this is an
 * object rather than a `?string`:
 *
 *   omit it entirely  the server default applies (Mailtrap is rolling out 1 year)
 *   at(…)             an explicit ISO 8601 instant
 *   never()           an explicit `null`, meaning no expiry
 *
 * `null` in the payload is "never", so a nullable string could not express
 * "leave it to the server" and "never expires" as separate things.
 */
final readonly class TokenExpiration
{
    private function __construct(public ?string $value) {}

    /** The token expires at this instant. Mailtrap rejects the past and >5 years out with a 422. */
    public static function at(\DateTimeInterface|string $value): self
    {
        if ($value instanceof \DateTimeInterface) {
            return new self($value->format(\DateTimeInterface::ATOM));
        }

        $value = trim($value);
        if ($value === '') {
            throw new MailException('TokenExpiration: an empty expiry is not "never" — use TokenExpiration::never().');
        }

        return new self($value);
    }

    /** The token never expires. */
    public static function never(): self
    {
        return new self(null);
    }
}
