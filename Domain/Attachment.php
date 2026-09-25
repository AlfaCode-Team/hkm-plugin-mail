<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/**
 * A file attachment or an inline (embedded) image.
 *
 * Two constructors: fromPath() reads a file lazily at build time; fromData()
 * carries raw bytes (generated PDFs, in-memory images). Inline attachments carry
 * a Content-ID so HTML can reference them as `<img src="cid:...">`.
 */
final readonly class Attachment
{
    private function __construct(
        public string $name,        // filename shown to the recipient
        public string $mimeType,
        public bool $inline,
        public string $cid,         // Content-ID (inline only)
        public ?string $path,       // read at build time when set
        public ?string $data,       // raw bytes when path is null
    ) {
        // The mime type is concatenated into the part's `Content-Type:` header
        // exactly as name and cid are into theirs, so it needs the same guard.
        // It was the one field of the three without one, and fromArray() now
        // lets a queue payload reach it.
        if (preg_match('/[\r\n\x00]/', $name . $cid . $mimeType) === 1) {
            throw new MailException('Attachment name/cid/mime-type may not contain control characters.');
        }
    }

    public static function fromPath(string $path, string $name = '', string $mimeType = ''): self
    {
        return new self(
            name:     $name !== '' ? $name : basename($path),
            mimeType: $mimeType !== '' ? $mimeType : self::guessMime($path),
            inline:   false,
            cid:      '',
            path:     $path,
            data:     null,
        );
    }

    public static function fromData(string $data, string $name, string $mimeType = 'application/octet-stream'): self
    {
        return new self($name, $mimeType, false, '', null, $data);
    }

    /** Inline image referenced from HTML via cid:. */
    public static function inline(string $pathOrData, string $cid, string $name = '', string $mimeType = '', bool $isPath = true): self
    {
        return new self(
            name:     $name !== '' ? $name : ($isPath ? basename($pathOrData) : $cid),
            mimeType: $mimeType !== '' ? $mimeType : ($isPath ? self::guessMime($pathOrData) : 'application/octet-stream'),
            inline:   true,
            cid:      $cid,
            path:     $isPath ? $pathOrData : null,
            data:     $isPath ? null : $pathOrData,
        );
    }

    /** Resolve the raw bytes (reads the file when path-backed). */
    public function contents(): string
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if ($this->path === null || is_file($this->path) === false || is_readable($this->path) === false) {
            throw new MailException("Attachment not readable: {$this->path}");
        }
        $bytes = file_get_contents($this->path);
        if ($bytes === false) {
            throw new MailException("Failed to read attachment: {$this->path}");
        }
        return $bytes;
    }

    /**
     * A JSON-safe snapshot, with the bytes RESOLVED.
     *
     * A path-backed attachment is read here rather than carried as a path,
     * because this exists to cross a queue: by the time a worker picks the job
     * up the temp upload has usually been cleaned away, and a path that still
     * resolves in the worker may not be the same file any more. Reading once,
     * at enqueue time, is also what the MIME path does — {@see \Plugins\Mail\Infrastructure\Mime\MimeBuilder}
     * builds the base64 part before the message is queued.
     *
     * @return array{name: string, mime_type: string, inline: bool, cid: string, data: string}
     */
    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'mime_type' => $this->mimeType,
            'inline'    => $this->inline,
            'cid'       => $this->cid,
            'data'      => base64_encode($this->contents()),
        ];
    }

    /**
     * Rebuild from {@see self::toArray()} — always data-backed, never a path.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bytes = base64_decode((string) ($data['data'] ?? ''), true);

        if ($bytes === false) {
            throw new MailException('Attachment payload is not valid base64.');
        }

        return new self(
            name:     (string) ($data['name'] ?? ''),
            mimeType: (string) ($data['mime_type'] ?? 'application/octet-stream'),
            inline:   (bool) ($data['inline'] ?? false),
            cid:      (string) ($data['cid'] ?? ''),
            path:     null,
            data:     $bytes,
        );
    }

    private static function guessMime(string $path): string
    {
        if (function_exists('mime_content_type') && is_file($path)) {
            $type = @mime_content_type($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }
        return 'application/octet-stream';
    }
}
