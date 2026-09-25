<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * Narrows a suppression listing. `lastId` is the cursor — pass the previous
 * page's last id to get the next page.
 */
final readonly class SuppressionsFilter
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
