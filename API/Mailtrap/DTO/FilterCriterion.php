<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * One comparison in an email-log filter.
 *
 * Two named constructors rather than a nullable value, because `empty` /
 * `not_empty` take no value and the API answers 422 when one is sent — a
 * nullable argument would make that a runtime surprise instead of a call you
 * cannot write.
 */
final readonly class FilterCriterion
{
    private function __construct(
        public EmailLogsOperator $operator,
        public string|int|array|null $value,
    ) {}

    public static function withValue(EmailLogsOperator $operator, string|int|array $value): self
    {
        if ($operator === EmailLogsOperator::IsEmpty || $operator === EmailLogsOperator::IsNotEmpty) {
            throw new MailException(
                "FilterCriterion: the '{$operator->value}' operator takes no value — use withoutValue().",
            );
        }

        return new self($operator, $value);
    }

    public static function withoutValue(EmailLogsOperator $operator): self
    {
        return new self($operator, null);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = ['operator' => $this->operator->value];

        if ($this->value !== null) {
            $payload['value'] = $this->value;
        }

        return $payload;
    }
}
