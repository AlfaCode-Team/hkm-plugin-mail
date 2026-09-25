<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** Narrows a tracking-opt-out listing; `lastId` is the pagination cursor. */
final readonly class TrackingOptOutsFilter
{
    public function __construct(
        public ?string $email = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public ?string $lastId = null,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return array_filter([
            'email'      => $this->email,
            'start_time' => $this->startTime,
            'end_time'   => $this->endTime,
            'last_id'    => $this->lastId,
        ], static fn(?string $value): bool => $value !== null);
    }
}
