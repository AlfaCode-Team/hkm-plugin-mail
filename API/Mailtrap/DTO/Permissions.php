<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

use Plugins\Mail\Domain\MailException;

/**
 * A set of grants and revocations applied in ONE bulk call.
 *
 * Mailtrap's permissions endpoint replaces the whole set it is given, so the
 * grants and the revocations have to travel together — sending them as separate
 * calls leaves a window where a user has neither.
 */
final class Permissions
{
    /** @var list<Permission> */
    private array $permissions = [];

    public function __construct(Permission ...$permissions)
    {
        foreach ($permissions as $permission) {
            $this->add($permission);
        }
    }

    public function add(Permission $permission): self
    {
        $this->permissions[] = $permission;

        return $this;
    }

    /** @return list<Permission> */
    public function all(): array
    {
        return $this->permissions;
    }

    /** @return list<array<string,mixed>> */
    public function toArray(): array
    {
        if ($this->permissions === []) {
            throw new MailException('Permissions: at least one permission is required.');
        }

        return array_map(static fn(Permission $p): array => $p->toArray(), $this->permissions);
    }
}
