<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/**
 * Any mail-building or delivery fault.
 *
 * ONE exception type across building, SMTP delivery and the Mailtrap API, so a
 * caller writes one catch instead of learning a hierarchy. The official SDK
 * splits 4xx from 5xx into separate classes; this keeps the single type but
 * carries the status on it, because the distinction those classes exist to
 * express is worth keeping even when the classes are not:
 *
 *   4xx  the request is wrong and will be wrong next time — an unverified
 *        sending domain, a malformed address, a subject sent alongside a
 *        template. Retrying spends the budget and dead-letters anyway.
 *   5xx  Mailtrap had a problem. Retrying is the correct response.
 *
 * Without the status the only way to tell those apart is matching on the
 * message text, which breaks the first time the API rewords an error.
 *
 * `$status` is 0 for faults that never reached an HTTP response at all — a
 * message with no From, a refused template combination, a socket that would not
 * open. Those are NOT all alike: the first two are permanent, the third is the
 * most transient failure there is. Since 0 cannot tell them apart,
 * {@see self::isPermanent()} refuses to guess and reports only a confirmed 4xx
 * as permanent.
 */
final class MailException extends \RuntimeException
{
    /**
     * @param int            $status  the HTTP status this fault came from, or 0
     *                                when it did not come from an HTTP response
     * @param array<string,mixed> $context structured detail a caller may need to
     *                                act on, mirroring the kernel's own
     *                                `FrameworkException::$context`. Used by a
     *                                partially-failed batch to carry the ids
     *                                that DID send — see
     *                                {@see \Plugins\Mail\Infrastructure\Transport\MailtrapTransport::sendBatch()}.
     *                                Never holds message bodies.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $status = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Is this fault KNOWN to recur if the same request is sent again?
     *
     * True only for a confirmed 4xx. Everything else — a 5xx, a timeout, a
     * connection refused, a build fault that never reached the wire — reports
     * false, which is the conservative answer for mail specifically: a wasted
     * retry costs one request against a bounded budget that dead-letters
     * anyway, while a retry wrongly suppressed drops a real message on a
     * network blip. Given an ambiguous failure, try again.
     *
     * So this is a narrow "definitely do not bother" signal, not a full
     * retry policy — `false` means "not known to be permanent", not "will
     * succeed next time".
     */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }
}
