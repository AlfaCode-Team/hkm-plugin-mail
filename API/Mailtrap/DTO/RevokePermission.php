<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * Remove access to one resource.
 *
 * Expressed as `_destroy: true` inside the same bulk payload as a grant, which
 * is how the API models it — a revocation is not a DELETE of its own.
 */
final readonly class RevokePermission implements Permission
{
    public string $resourceId;
    public string $resourceType;

    /**
     * @param PermissionResourceType|string $resourceType prefer the enum; a raw
     *        string stays accepted so a resource type Mailtrap adds after this
     *        release is usable without waiting for a new one
     */
    public function __construct(
        int|string $resourceId,
        PermissionResourceType|string $resourceType,
    ) {
        $this->resourceId   = (string) $resourceId;
        $this->resourceType = $resourceType instanceof PermissionResourceType
            ? $resourceType->value
            : $resourceType;
    }

    public function toArray(): array
    {
        return [
            'resource_id'   => $this->resourceId,
            'resource_type' => $this->resourceType,
            '_destroy'      => true,
        ];
    }
}
