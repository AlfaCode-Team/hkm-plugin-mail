<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** One entry in a {@see Permissions} set. */
interface Permission
{
    /** @return array<string,mixed> */
    public function toArray(): array;
}
