<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * The body of a stored email template.
 *
 * Named `…Data` rather than `EmailTemplate` because {@see \Plugins\Mail\API\Mailtrap\General\EmailTemplate}
 * is the API client for the same resource — the SDK gives both the same name in
 * different namespaces, which is exactly the import collision this avoids.
 */
final readonly class EmailTemplateData
{
    public function __construct(
        public string $name,
        public string $category,
        public string $subject,
        public string $bodyText,
        public string $bodyHtml,
    ) {
        if (trim($name) === '') {
            throw new MailException('EmailTemplateData: a template name is required.');
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'category'  => $this->category,
            'subject'   => $this->subject,
            'body_text' => $this->bodyText,
            'body_html' => $this->bodyHtml,
        ];
    }
}
