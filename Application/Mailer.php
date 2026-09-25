<?php

declare(strict_types=1);

namespace Plugins\Mail\Application;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Security\DkimSigner;
use Plugins\Mail\Infrastructure\Transport\MessageTransport;
use Plugins\Mail\Infrastructure\Transport\Transport;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * The mail façade — implements BOTH the kernel {@see MailPort} (view-based
 * send/queue used generically across the platform) and the richer
 * {@see MailerContract} (full Message builder).
 *
 * Pipeline per message: apply the default From → build MIME (MimeBuilder) →
 * optionally DKIM-sign (prepend the signature header) → hand the raw bytes and
 * envelope to the configured Transport (or enqueue via the QueuePort).
 */
final class Mailer implements MailPort, MailerContract
{
    public const QUEUE_JOB = 'mail.send';

    public function __construct(
        private readonly Transport $transport,
        private readonly MimeBuilder $mime,
        private readonly ?DkimSigner $dkim = null,
        private readonly ?ViewRendererContract $views = null,
        private readonly ?QueuePort $queue = null,
        private readonly string $fromEmail = '',
        private readonly string $fromName = '',
        private readonly string $charset = 'UTF-8',
        private readonly string $queueName = 'mail',
        /**
         * Used only to bound how many urgent mails may be delivered inline at
         * once. Optional: with no CachePort the guard cannot be enforced, so
         * urgent mail queues as before rather than letting every web worker
         * block on the SMTP socket at the same time.
         *
         * It must be a CROSS-PROCESS cache to mean anything. A per-process one
         * (an in-memory adapter) gives every PHP-FPM worker its own private set
         * of slots, so the cap is silently multiplied by the worker count.
         */
        private readonly ?CachePort $cache = null,
        /** @var list<string> View names delivered inline; a trailing '*' is a prefix. */
        private readonly array $urgentViews = [],
        private readonly int $urgentMaxInline = 0,
        private readonly int $urgentLockTtl = 20,
    ) {}

    // ── MailerContract (rich API) ────────────────────────────────────────────

    public function message(): Message
    {
        // `MAIL_CHARSET=` (written active but empty) is the documented .env
        // footgun: env() returns '' and that beats config/mail.php's own
        // default. It used to render a broken `charset=` header; now that
        // Message::charset() validates, it would throw on every message. Fall
        // back rather than turn a stale .env line into an outage.
        $m = Message::make()->charset($this->charset !== '' ? $this->charset : 'UTF-8');
        if ($this->fromEmail !== '') {
            $m->from($this->fromEmail, $this->fromName);
        }
        return $m;
    }

    public function dispatch(Message $message): void
    {
        $this->applyDefaultFrom($message);
        $this->deliver($message);
    }

    public function enqueue(Message $message): string
    {
        $this->applyDefaultFrom($message);

        if ($this->queue === null) {
            $this->deliver($message);
            return '';
        }

        return $this->queue->push(self::QUEUE_JOB, $this->jobPayload($message), $this->queueName);
    }

    /**
     * Send one request per batch instead of one per recipient.
     *
     * A transport with a real batch endpoint (the Mailtrap API) sends them in a
     * single call with the shared fields hoisted into `$base`; everything else
     * loops, which is the same mail either way, just more round trips. The
     * fallback is what makes this safe to call from ordinary code: it does not
     * become a runtime error the day someone switches MAIL_TRANSPORT to smtp.
     *
     * @param  list<Message> $messages one per recipient
     * @param  ?Message      $base     fields shared by all of them; it may NOT
     *                                 carry recipients of its own
     * @return list<list<string>>      provider message ids, in the order given
     */
    public function dispatchBatch(array $messages, ?Message $base = null): array
    {
        $messages = array_values($messages);

        foreach ($messages as $message) {
            $this->applyDefaultFrom($message);
        }

        if ($this->transport instanceof MessageTransport) {
            if ($base !== null && $base->getFrom() === null && $this->fromEmail !== '') {
                $base->from($this->fromEmail, $this->fromName);
            }

            return $this->transport->sendBatch($messages, $base);
        }

        foreach ($messages as $message) {
            $this->deliver($message);
        }

        return array_fill(0, count($messages), []);
    }

    /**
     * Deliver NOW if there is spare capacity, otherwise queue.
     *
     * For mail whose value expires in minutes -- a verification link, a
     * password reset -- where queueing means "delivered whenever a worker next
     * runs", which is frequently never.
     *
     * Capacity is `urgent.max_inline` concurrent inline sends, held as named
     * locks so the limit spans every PHP-FPM worker rather than each one
     * counting to the limit alone. When they are all taken the message goes to
     * the queue: a burst of signups must not leave every web worker blocked on
     * the same SMTP socket.
     *
     * A transport failure is NOT propagated -- the message is queued instead,
     * so a momentary SMTP problem degrades urgent mail to normal mail rather
     * than losing it and failing the request the user is waiting on.
     *
     * @return string '' when delivered inline, else the queued job id.
     */
    public function dispatchNow(Message $message): string
    {
        $slot = $this->claimInlineSlot();

        if ($slot === null) {
            return $this->enqueue($message);
        }

        try {
            $this->dispatch($message);

            return '';
        } catch (\Throwable) {
            return $this->enqueue($message);
        } finally {
            $slot->release();
        }
    }

    /**
     * Compile the full MIME (headers + body, DKIM-signed if configured) without
     * sending.
     *
     * Always renders MIME, even when an API transport is configured — the
     * message is the same one either way, and a preview that changed shape with
     * MAIL_TRANSPORT would stop being useful for reading what was written. What
     * an API transport actually PUTS ON THE WIRE is the JSON document built by
     * MailtrapPayload, which is not this.
     */
    public function preview(Message $message): string
    {
        $this->applyDefaultFrom($message);

        return $this->compile($message)['mime'];
    }

    // ── MailPort (kernel, view-based) ────────────────────────────────────────

    /** @param string|array<int|string,string> $to */
    public function send(string|array $to, string $subject, string $view, array $data = []): void
    {
        $this->dispatch($this->fromView($to, $subject, $view, $data));
    }

    /**
     * @param string|array<int|string,string> $to
     *
     * Urgent views short-circuit to inline delivery (falling back to the queue
     * when capacity is gone). Callers keep using the kernel MailPort and need
     * to know nothing about this -- which matters because the callers that send
     * verification mail hold a MailPort, not this class, and the port has no
     * way to express priority.
     */
    public function queue(string|array $to, string $subject, string $view, array $data = []): string
    {
        $message = $this->fromView($to, $subject, $view, $data);

        return $this->isUrgentView($view)
            ? $this->dispatchNow($message)
            : $this->enqueue($message);
    }

    /** Does this view name match one of the configured urgent patterns? */
    public function isUrgentView(string $view): bool
    {
        foreach ($this->urgentViews as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($view, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($view === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take one of the bounded inline-delivery slots, or null when they are all
     * in use (or the guard cannot be enforced at all).
     *
     * Each slot carries a TTL so a process killed mid-send frees its slot
     * instead of permanently reducing capacity.
     */
    private function claimInlineSlot(): ?Lock
    {
        if ($this->cache === null || $this->urgentMaxInline < 1) {
            return null;
        }

        for ($slot = 0; $slot < $this->urgentMaxInline; $slot++) {
            try {
                $lock = $this->cache->lock("mail:inline:{$slot}", max(1, $this->urgentLockTtl));

                if ($lock->acquire()) {
                    return $lock;
                }
            } catch (\Throwable) {
                // A cache backend that cannot lock must not stop mail going out;
                // fall through and let the message queue.
                return null;
            }
        }

        return null;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Fill in the configured default From when the caller set none.
     *
     * Runs before EVERY delivery path (inline, queued, batched, preview) rather
     * than inside compile(), because an API transport never compiles MIME and
     * would otherwise send a message with no sender at all.
     */
    private function applyDefaultFrom(Message $message): void
    {
        if ($message->getFrom() !== null) {
            return;
        }
        if ($this->fromEmail === '') {
            throw new MailException('No From address on the message and no default configured.');
        }

        $message->from($this->fromEmail, $this->fromName);
    }

    /**
     * Hand the message to the transport in whichever shape it takes.
     *
     * An API transport is given the Message; everything else is given MIME. The
     * DKIM signer only participates in the MIME path — see
     * {@see MessageTransport} for why signing does not apply to an API send.
     */
    private function deliver(Message $message): void
    {
        if ($this->transport instanceof MessageTransport) {
            $this->transport->sendMessage($message);

            return;
        }

        $compiled = $this->compile($message);
        $this->transport->send($compiled['from'], $compiled['recipients'], $compiled['mime']);
    }

    /**
     * What goes on the queue.
     *
     * Two shapes, because the two transport kinds need different things in the
     * worker: a MIME transport gets the already-built, already-signed bytes
     * (`from`/`recipients`/`mime`), an API transport gets the serialised
     * message (`message`) and builds the request itself. {@see \Plugins\Mail\Application\Jobs\SendMailJob}
     * dispatches on which key is present.
     *
     * @return array<string,mixed>
     */
    private function jobPayload(Message $message): array
    {
        return $this->transport instanceof MessageTransport
            ? ['message' => $message->toArray()]
            : $this->compile($message);
    }

    /** @return array{from: string, recipients: list<string>, mime: string} */
    private function compile(Message $message): array
    {
        $built   = $this->mime->build($message);
        $headers = $built['headers'];
        $body    = $built['body'];

        if ($this->dkim !== null) {
            array_unshift($headers, $this->dkim->sign($headers, $body));
        }

        /** @var \Plugins\Mail\Domain\Address $from */
        $from     = $message->getFrom();
        $envelope = $message->getReturnPath() ?? $message->getSender()?->email ?? $from->email;

        return [
            'from'       => $envelope,
            'recipients' => $message->recipientEmails(),
            'mime'       => implode("\r\n", $headers) . "\r\n\r\n" . $body,
        ];
    }

    /** @param string|array<int|string,string> $to */
    private function fromView(string|array $to, string $subject, string $view, array $data): Message
    {
        $message = $this->message()->subject($subject)->html($this->render($view, $data));

        foreach ($this->normaliseRecipients($to) as $email => $name) {
            $message->to($email, $name);
        }

        return $message;
    }

    private function render(string $view, array $data): string
    {
        // With the View plugin, treat $view as a template name; without it, the
        // caller passed raw HTML (so MailPort works even with no renderer bound).
        if ($this->views === null) {
            return $view;
        }

        // render()'s second argument is render OPTIONS (layout/cache), NOT view
        // data — template variables must go through setData(), otherwise nothing
        // is extracted and every placeholder renders empty. 'raw' because mail
        // templates escape what they print themselves.
        return $this->views->setData($data, 'raw')->render($view);
    }

    /**
     * @param string|array<int|string,string> $to
     * @return array<string,string> email => name
     */
    private function normaliseRecipients(string|array $to): array
    {
        if (is_string($to)) {
            return [$to => ''];
        }

        $out = [];
        foreach ($to as $key => $value) {
            if (is_int($key)) {
                $out[$value] = '';       // list of emails
            } else {
                $out[$key] = $value;     // email => name
            }
        }
        return $out;
    }
}
