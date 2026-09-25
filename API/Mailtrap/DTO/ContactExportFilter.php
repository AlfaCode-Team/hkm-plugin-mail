<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** One condition narrowing a contact export. */
final readonly class ContactExportFilter
{
    public string $name;
    public string $operator;

    public function __construct(string $name, string $operator, public mixed $value = null)
    {
        $name     = trim($name);
        $operator = trim($operator);

        if ($name === '' || $operator === '') {
            throw new MailException('ContactExportFilter: both a field name and an operator are required.');
        }

        $this->name     = $name;
        $this->operator = $operator;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'operator' => $this->operator, 'value' => $this->value];
    }
}
