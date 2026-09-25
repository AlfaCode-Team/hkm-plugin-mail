<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * Grant or change access to one resource.
 *
 * The endpoint is an upsert: sending a `resource_type` + `resource_id` pair that
 * already has a permission UPDATES it; a new pair creates one. There is no
 * separate create/update call, which is why this is one class.
 */
final readonly class GrantPermission implements Permission
{
    public string $resourceId;
    public string $resourceType;
    public string $accessLevel;

    /**
     * @param PermissionResourceType|string $resourceType prefer the enum; a raw
     *        string stays accepted so a resource type Mailtrap adds after this
     *        release is usable without waiting for a new one
     * @param int|string $accessLevel Mailtrap's numeric level, or its name
     */
    public function __construct(
        int|string $resourceId,
        PermissionResourceType|string $resourceType,
        int|string $accessLevel,
    ) {
        $this->resourceId   = (string) $resourceId;
        $this->resourceType = $resourceType instanceof PermissionResourceType
            ? $resourceType->value
            : $resourceType;
        $this->accessLevel  = (string) $accessLevel;
    }

    public function toArray(): array
    {
        return [
            'resource_id'   => $this->resourceId,
            'resource_type' => $this->resourceType,
            'access_level'  => $this->accessLevel,
        ];
    }
}
