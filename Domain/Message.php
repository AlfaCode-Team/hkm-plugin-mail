<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/**
 * A fluent, mutable e-mail message builder (PHPMailer-equivalent surface).
 *
 * Covers: From/Sender, To/Cc/Bcc, Reply-To, Subject, HTML + plain-text alt body,
 * file/raw/inline attachments, custom headers, priority, charset, read receipts,
 * envelope Return-Path, and free-form tags/metadata (for logging/webhooks).
 *
 * All addresses flow through {@see Address}, so CR/LF header injection is
 * impossible regardless of where the values came from.
 */
final class Message
{
    private ?Address $from = null;
    private ?Address $sender = null;              // envelope / Sender header
    private ?string $returnPath = null;

    /** @var list<Address> */
    private array $to = [];
    /** @var list<Address> */
    private array $cc = [];
    /** @var list<Address> */
    private array $bcc = [];
    /** @var list<Address> */
    private array $replyTo = [];

    private string $subject = '';
    private string $html = '';
    private string $text = '';
    private string $charset = 'UTF-8';
    private Priority $priority = Priority::Normal;
    private ?Address $confirmReadingTo = null;    // Disposition-Notification-To

    /** @var list<Attachment> */
    private array $attachments = [];
    /** @var array<string,string> */
    private array $headers = [];
    /** @var array<string,scalar> */
    private array $metadata = [];

    // ── provider fields ──────────────────────────────────────────────────────
    // Not MIME headers, and not invented here: category / custom variables /
    // stored template + variables are the four things every transactional API
    // this plugin can speak (Mailtrap, and the same shape at Postmark, SendGrid
    // and Mailgun) accepts ALONGSIDE the message rather than inside it. They
    // live on the Message so a caller does not have to know which transport is
    // configured; a MIME transport simply ignores them.

    private ?string $category = null;
    /** @var array<string,string> */
    private array $customVariables = [];
    private ?string $templateUuid = null;
    /** @var array<string,mixed> */
    private array $templateVariables = [];

    public static function make(): self
    {
        return new self();
    }

    // ── envelope / from ──────────────────────────────────────────────────────

    public function from(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);
        return $this;
    }

    /** Distinct envelope sender (Sender header + default Return-Path). */
    public function sender(string $email, string $name = ''): self
    {
        $this->sender = new Address($email, $name);
        return $this;
    }

    public function returnPath(string $email): self
    {
        $this->returnPath = (new Address($email))->email;
        return $this;
    }

    // ── recipients ───────────────────────────────────────────────────────────

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);
        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);
        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);
        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo[] = new Address($email, $name);
        return $this;
    }

    // ── content ──────────────────────────────────────────────────────────────

    public function subject(string $subject): self
    {
        // Strip control chars — the subject becomes a header.
        $this->subject = (string) preg_replace('/[\r\n\x00]/', '', $subject);
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * The charset is written into a HEADER — `Content-Type: text/plain;
     * charset=…` — and passed to every MIME-encoded display name, so it is a
     * header-injection vector exactly like an address is.
     *
     * That mattered little while it came only from config. It matters now that
     * {@see self::fromArray()} rebuilds a message from a QUEUE payload: without
     * this check a crafted `charset` smuggles a real top-level `Bcc:` past the
     * guards on every other field, and a silent extra recipient is the precise
     * outcome those guards exist to prevent.
     *
     * RFC 2978 charset names are short printable tokens; anything else is
     * refused rather than sanitised, because a charset that needed stripping was
     * never a charset.
     */
    public function charset(string $charset): self
    {
        $charset = trim($charset);

        if (preg_match('/^[A-Za-z0-9._:+-]{1,64}$/', $charset) !== 1) {
            throw new MailException("Invalid charset: {$charset}");
        }

        $this->charset = $charset;
        return $this;
    }

    public function priority(Priority $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /** Request a read receipt to this address (Disposition-Notification-To). */
    public function confirmReadingTo(string $email, string $name = ''): self
    {
        $this->confirmReadingTo = new Address($email, $name);
        return $this;
    }

    // ── attachments ──────────────────────────────────────────────────────────

    public function attach(string $path, string $name = '', string $mimeType = ''): self
    {
        $this->attachments[] = Attachment::fromPath($path, $name, $mimeType);
        return $this;
    }

    public function attachData(string $data, string $name, string $mimeType = 'application/octet-stream'): self
    {
        $this->attachments[] = Attachment::fromData($data, $name, $mimeType);
        return $this;
    }

    /** Embed an image and reference it in HTML as `<img src="cid:$cid">`. */
    public function embed(string $path, string $cid, string $name = '', string $mimeType = ''): self
    {
        $this->attachments[] = Attachment::inline($path, $cid, $name, $mimeType, isPath: true);
        return $this;
    }

    public function embedData(string $data, string $cid, string $name = '', string $mimeType = 'application/octet-stream'): self
    {
        $this->attachments[] = Attachment::inline($data, $cid, $name, $mimeType, isPath: false);
        return $this;
    }

    // ── headers / metadata ───────────────────────────────────────────────────

    public function header(string $name, string $value): self
    {
        if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
            throw new MailException('Custom headers may not contain control characters.');
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /** Arbitrary tag for logging / webhooks (not sent unless you also add a header). */
    public function tag(string $key, string|int|float|bool $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    // ── provider fields (honoured by API transports, ignored by MIME ones) ───

    /**
     * A single label the provider groups and reports this message under.
     *
     * Mailtrap accepts exactly ONE category and rejects the message when more
     * are supplied, so this REPLACES any previous value rather than appending —
     * a silent second category would fail at the API with nothing in the
     * application pointing at the call that added it.
     */
    public function category(string $category): self
    {
        $category = trim($category);

        if ($category === '') {
            throw new MailException('A category may not be empty.');
        }
        // 255 is Mailtrap's documented limit; longer is a 400 at send time.
        if (mb_strlen($category) > 255) {
            throw new MailException('A category may not exceed 255 characters.');
        }
        if (preg_match('/[\r\n\x00]/', $category) === 1) {
            throw new MailException('A category may not contain control characters.');
        }

        $this->category = $category;
        return $this;
    }

    /**
     * Arbitrary key/value echoed back on the provider's webhooks and logs —
     * the reliable way to correlate a delivery event with the record that
     * caused it (an order id, a tenant id) without parsing the message.
     *
     * Values are stringified because that is what the API stores; use
     * {@see self::templateVariable()} when you need real nested data.
     */
    public function customVariable(string $name, string|int|float|bool $value): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new MailException('A custom variable name may not be empty.');
        }

        $this->customVariables[$name] = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default         => (string) $value,
        };

        return $this;
    }

    /** @param array<string,string|int|float|bool> $variables */
    public function customVariables(array $variables): self
    {
        foreach ($variables as $name => $value) {
            $this->customVariable((string) $name, $value);
        }
        return $this;
    }

    /**
     * Render this message from a template STORED AT THE PROVIDER, identified by
     * its uuid, instead of from subject/html/text.
     *
     * The two are mutually exclusive at the API: Mailtrap answers
     * "'subject' is not allowed with 'template_uuid'". The payload builder
     * therefore omits subject/text/html/category whenever a template is set —
     * see {@see \Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload}.
     */
    public function template(string $uuid, array $variables = []): self
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            throw new MailException('A template uuid may not be empty.');
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1) {
            throw new MailException("Template uuid is not a UUID: {$uuid}");
        }

        $this->templateUuid = $uuid;
        return $this->templateVariables($variables);
    }

    /** Values may be scalars, lists or maps — the API takes them as JSON. */
    public function templateVariable(string $name, mixed $value): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new MailException('A template variable name may not be empty.');
        }

        $this->templateVariables[$name] = $value;
        return $this;
    }

    /** @param array<string,mixed> $variables */
    public function templateVariables(array $variables): self
    {
        foreach ($variables as $name => $value) {
            $this->templateVariable((string) $name, $value);
        }
        return $this;
    }

    // ── accessors (used by the MIME builder / transports) ────────────────────

    public function getFrom(): ?Address { return $this->from; }
    public function getSender(): ?Address { return $this->sender; }
    public function getReturnPath(): ?string { return $this->returnPath; }
    /** @return list<Address> */ public function getTo(): array { return $this->to; }
    /** @return list<Address> */ public function getCc(): array { return $this->cc; }
    /** @return list<Address> */ public function getBcc(): array { return $this->bcc; }
    /** @return list<Address> */ public function getReplyTo(): array { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getHtml(): string { return $this->html; }
    public function getText(): string { return $this->text; }
    public function getCharset(): string { return $this->charset; }
    public function getPriority(): Priority { return $this->priority; }
    public function getConfirmReadingTo(): ?Address { return $this->confirmReadingTo; }
    /** @return list<Attachment> */ public function getAttachments(): array { return $this->attachments; }
    /** @return array<string,string> */ public function getHeaders(): array { return $this->headers; }
    /** @return array<string,scalar> */ public function getMetadata(): array { return $this->metadata; }
    public function getCategory(): ?string { return $this->category; }
    /** @return array<string,string> */ public function getCustomVariables(): array { return $this->customVariables; }
    public function getTemplateUuid(): ?string { return $this->templateUuid; }
    /** @return array<string,mixed> */ public function getTemplateVariables(): array { return $this->templateVariables; }
    public function hasTemplate(): bool { return $this->templateUuid !== null; }

    /** All RCPT recipients (to + cc + bcc) as bare addresses. @return list<string> */
    public function recipientEmails(): array
    {
        $all = [];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $a) {
            $all[$a->email] = true;   // dedupe
        }
        return array_keys($all);
    }

    // ── queue serialisation ──────────────────────────────────────────────────

    /**
     * A JSON-safe snapshot of this message, for handing to the QueuePort.
     *
     * Needed because an API transport is not given MIME: the MIME path can
     * enqueue the built bytes, but a structured send has to enqueue the MESSAGE
     * and build the payload in the worker. See {@see \Plugins\Mail\Application\Mailer::enqueue()}.
     *
     * Attachment bytes are MATERIALISED here (see {@see Attachment::toArray()}),
     * so a path-backed attachment survives the file being moved, rewritten or
     * cleaned up between the web request that queued the mail and the worker
     * that sends it — the MIME path gets that for free by building at enqueue
     * time, and dropping it here would make queued attachments fail only in
     * production, only sometimes.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $address = static fn(?Address $a): ?array => $a === null ? null : ['email' => $a->email, 'name' => $a->name];
        $list    = static fn(array $addresses): array => array_map(
            static fn(Address $a): array => ['email' => $a->email, 'name' => $a->name],
            $addresses,
        );

        return [
            'from'               => $address($this->from),
            'sender'             => $address($this->sender),
            'return_path'        => $this->returnPath,
            'to'                 => $list($this->to),
            'cc'                 => $list($this->cc),
            'bcc'                => $list($this->bcc),
            'reply_to'           => $list($this->replyTo),
            'subject'            => $this->subject,
            'html'               => $this->html,
            'text'               => $this->text,
            'charset'            => $this->charset,
            'priority'           => $this->priority->value,
            'confirm_reading_to' => $address($this->confirmReadingTo),
            'attachments'        => array_map(static fn(Attachment $a): array => $a->toArray(), $this->attachments),
            'headers'            => $this->headers,
            'metadata'           => $this->metadata,
            'category'           => $this->category,
            'custom_variables'   => $this->customVariables,
            'template_uuid'      => $this->templateUuid,
            'template_variables' => $this->templateVariables,
        ];
    }

    /**
     * Rebuild a message from {@see self::toArray()}.
     *
     * Everything is put back through the ORDINARY fluent setters, so a payload
     * that has been sitting in Redis is re-validated exactly like one built in
     * PHP — the Address constructor still rejects a CR/LF smuggled into a
     * recipient, and a queue anyone can write to cannot become a way around the
     * header-injection guards.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $message = new self();

        if (is_array($data['from'] ?? null)) {
            $message->from((string) ($data['from']['email'] ?? ''), (string) ($data['from']['name'] ?? ''));
        }
        if (is_array($data['sender'] ?? null)) {
            $message->sender((string) ($data['sender']['email'] ?? ''), (string) ($data['sender']['name'] ?? ''));
        }
        if (is_string($data['return_path'] ?? null) && $data['return_path'] !== '') {
            $message->returnPath($data['return_path']);
        }

        foreach (['to', 'cc', 'bcc', 'reply_to'] as $field) {
            $method = $field === 'reply_to' ? 'replyTo' : $field;
            foreach ((array) ($data[$field] ?? []) as $address) {
                if (is_array($address)) {
                    $message->{$method}((string) ($address['email'] ?? ''), (string) ($address['name'] ?? ''));
                }
            }
        }

        $message
            ->subject((string) ($data['subject'] ?? ''))
            ->html((string) ($data['html'] ?? ''))
            ->text((string) ($data['text'] ?? ''))
            ->charset((string) ($data['charset'] ?? 'UTF-8'))
            ->priority(Priority::tryFrom((int) ($data['priority'] ?? Priority::Normal->value)) ?? Priority::Normal);

        if (is_array($data['confirm_reading_to'] ?? null)) {
            $message->confirmReadingTo(
                (string) ($data['confirm_reading_to']['email'] ?? ''),
                (string) ($data['confirm_reading_to']['name'] ?? ''),
            );
        }

        foreach ((array) ($data['attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $message->attachments[] = Attachment::fromArray($attachment);
            }
        }

        foreach ((array) ($data['headers'] ?? []) as $name => $value) {
            $message->header((string) $name, (string) $value);
        }
        foreach ((array) ($data['metadata'] ?? []) as $name => $value) {
            if (is_scalar($value)) {
                $message->tag((string) $name, $value);
            }
        }

        if (is_string($data['category'] ?? null) && $data['category'] !== '') {
            $message->category($data['category']);
        }
        $message->customVariables(array_filter((array) ($data['custom_variables'] ?? []), 'is_scalar'));

        if (is_string($data['template_uuid'] ?? null) && $data['template_uuid'] !== '') {
            $message->template($data['template_uuid'], (array) ($data['template_variables'] ?? []));
        }

        return $message;
    }
}
