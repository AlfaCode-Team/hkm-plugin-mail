<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** The subject and bodies a campaign renders, with its merge tags. */
final readonly class TemplateAttributes
{
    /** @param ?array<string,mixed> $mergeTags */
    public function __construct(
        public ?string $subject = null,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?array $mergeTags = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = array_filter([
            'subject'   => $this->subject,
            'body_html' => $this->bodyHtml,
            'body_text' => $this->bodyText,
        ], static fn(?string $value): bool => $value !== null);

        if ($this->mergeTags !== null) {
            $payload['merge_tags'] = $this->mergeTags;
        }

        return $payload;
    }
}
