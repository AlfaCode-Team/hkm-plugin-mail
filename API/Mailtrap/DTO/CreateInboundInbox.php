<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/** A new inbound inbox inside a folder, optionally bound to a sending domain. */
final readonly class CreateInboundInbox
{
    public function __construct(public string $name, public ?int $domainId = null)
    {
        if (trim($name) === '') {
            throw new MailException('CreateInboundInbox: an inbox name is required.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = ['name' => $this->name];

        if ($this->domainId !== null) {
            $payload['domain_id'] = $this->domainId;
        }

        return $payload;
    }
}
