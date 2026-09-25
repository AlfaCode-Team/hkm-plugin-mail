<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * The filter document for an email-log listing.
 *
 * Immutable and built with `with…()` so a base filter can be reused across
 * several queries without one of them mutating it for the others.
 *
 * Criterion names are checked against the set the API accepts: an unknown key
 * is DROPPED by Mailtrap, which returns a successful but unfiltered listing —
 * the worst kind of failure to debug, so a typo throws here instead.
 */
final readonly class EmailLogsListFilters
{
    public const CRITERIA = [
        'to', 'from', 'subject', 'status', 'events', 'clicks_count', 'opens_count',
        'client_ip', 'sending_ip', 'email_service_provider_response',
        'email_service_provider', 'recipient_mx', 'category', 'sending_domain_id',
        'sending_stream',
    ];

    /** @param array<string,FilterCriterion> $criteria */
    public function __construct(
        public ?string $sentAfter = null,
        public ?string $sentBefore = null,
        public array $criteria = [],
    ) {
        foreach (array_keys($criteria) as $name) {
            if (!in_array($name, self::CRITERIA, true)) {
                throw new MailException(sprintf(
                    "EmailLogsListFilters: unknown criterion '%s' (Mailtrap would ignore it and return an "
                    . 'UNFILTERED list). Accepted: %s.',
                    $name,
                    implode(', ', self::CRITERIA),
                ));
            }
        }
    }

    /** @param string $iso8601 e.g. 2026-01-31T00:00:00Z */
    public function withSentAfter(string $iso8601): self
    {
        return new self($iso8601, $this->sentBefore, $this->criteria);
    }

    public function withSentBefore(string $iso8601): self
    {
        return new self($this->sentAfter, $iso8601, $this->criteria);
    }

    public function withCriterion(string $name, FilterCriterion $criterion): self
    {
        return new self($this->sentAfter, $this->sentBefore, [...$this->criteria, $name => $criterion]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = array_filter([
            'sent_after'  => $this->sentAfter,
            'sent_before' => $this->sentBefore,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        foreach ($this->criteria as $name => $criterion) {
            $payload[$name] = $criterion->toArray();
        }

        return $payload;
    }
}
