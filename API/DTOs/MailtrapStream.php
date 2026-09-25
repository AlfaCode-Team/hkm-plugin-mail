<?php

declare(strict_types=1);

namespace Plugins\Mail\API\DTOs;

use Plugins\Mail\Domain\MailException;

/**
 * Which Mailtrap sending stream a message goes to.
 *
 * Mailtrap does not route by a field in the payload — the stream IS the host,
 * and each host is a different product with different quotas and a different
 * meaning for "delivered":
 *
 *   transactional  send.api.mailtrap.io      one-to-one mail a person triggered
 *   bulk           bulk.api.mailtrap.io      one-to-many mail (campaigns, digests)
 *   sandbox        sandbox.api.mailtrap.io   captured in an inbox, NEVER delivered
 *
 * Sandbox additionally puts the inbox id in the PATH, which is why
 * {@see MailtrapSettings} refuses a sandbox stream without one: the request
 * would otherwise be sent to `/api/send/` and 404 at runtime rather than fail
 * when the application is configured.
 */
enum MailtrapStream: string
{
    case Transactional = 'transactional';
    case Bulk          = 'bulk';
    case Sandbox       = 'sandbox';

    /** The API host this stream is served from. */
    public function host(): string
    {
        return match ($this) {
            self::Transactional => 'send.api.mailtrap.io',
            self::Bulk          => 'bulk.api.mailtrap.io',
            self::Sandbox       => 'sandbox.api.mailtrap.io',
        };
    }

    /** True when the inbox id belongs in the request path. */
    public function needsInbox(): bool
    {
        return $this === self::Sandbox;
    }

    /**
     * Parse a configured value, naming the accepted set on failure.
     *
     * `MailtrapStream::from()` would throw a bare \ValueError whose message does
     * not say what IS accepted — and this value comes from MAILTRAP_STREAM in a
     * `.env`, so a typo is the expected failure, not an exotic one.
     */
    public static function parse(string $value): self
    {
        $stream = self::tryFrom(strtolower(trim($value)));

        if ($stream === null) {
            throw new MailException(sprintf(
                "Unknown Mailtrap stream '%s' (expected one of: %s).",
                $value,
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $stream;
    }
}
