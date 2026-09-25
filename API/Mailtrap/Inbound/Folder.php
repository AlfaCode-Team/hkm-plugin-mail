<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\Inbound;

use Plugins\Mail\API\Mailtrap\AbstractMailtrapApi;
use Plugins\Mail\API\Mailtrap\DTO\CreateInboundFolder;
use Plugins\Mail\API\Mailtrap\DTO\UpdateInboundFolder;

/** Inbound folders — the containers inbound inboxes live in. */
final class Folder extends AbstractMailtrapApi
{
    /** @return array<mixed> */
    public function getList(): array
    {
        return $this->http->get($this->base());
    }

    /** @return array<mixed> */
    public function getById(int $folderId): array
    {
        return $this->http->get($this->base() . '/' . $folderId);
    }

    /** @return array<mixed> */
    public function create(CreateInboundFolder $folder): array
    {
        return $this->http->post($this->base(), $folder->toArray());
    }

    /** @return array<mixed> */
    public function update(int $folderId, UpdateInboundFolder $folder): array
    {
        return $this->http->patch($this->base() . '/' . $folderId, $folder->toArray());
    }

    /** @return array<mixed> */
    public function delete(int $folderId): array
    {
        return $this->http->delete($this->base() . '/' . $folderId);
    }

    private function base(): string
    {
        return '/api/inbound/folders';
    }
}
