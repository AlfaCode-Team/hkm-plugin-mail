<?php

declare(strict_types=1);

namespace Plugins\Mail\API;

/**
 * Verifies the signature on an inbound Mailtrap webhook.
 *
 * Mailtrap signs every webhook it sends by computing
 * `HMAC-SHA256(signing_secret, raw_request_body)` and putting the lowercase hex
 * digest in the `Mailtrap-Signature` header. A delivery/bounce/spam webhook is
 * an unauthenticated public endpoint — anyone who learns the URL can POST to
 * it — so an application that acts on the event (suppressing an address,
 * marking a user's mail as undeliverable) MUST check this first.
 *
 * Published from `API/` on purpose: a project's webhook controller needs it,
 * and reaching into `Infrastructure/` from outside the module is a GDA
 * violation. It is a pure static function over its arguments — no state, no
 * container, nothing to bind.
 *
 * ```php
 * // in the project's webhook controller
 * if (!MailtrapWebhookSignature::verify(
 *         $request->getContent(),                       // the RAW body
 *         (string) $request->header('Mailtrap-Signature'),
 *         env('MAILTRAP_WEBHOOK_SECRET', ''),
 * )) {
 *     return Response::unauthorized();
 * }
 * ```
 */
final class MailtrapWebhookSignature
{
    /** SHA-256 as lowercase hex: 32 bytes, 64 characters. */
    public const SIGNATURE_LENGTH = 64;

    /**
     * @param string $payload       the raw request body, byte for byte as received.
     *                              Do NOT decode and re-encode the JSON first —
     *                              re-serialising reorders keys and changes
     *                              whitespace, and the signature is over the
     *                              original bytes, so every event would fail.
     * @param string $signature     the `Mailtrap-Signature` header value
     * @param string $signingSecret the webhook's `signing_secret`
     *
     * Never throws: every input that can plausibly arrive over the wire
     * (missing header, empty body, wrong length, non-hex) returns false, so a
     * controller can call it directly without a try/catch that would otherwise
     * become the way an unsigned request gets through.
     */
    public static function verify(string $payload, string $signature, string $signingSecret): bool
    {
        if ($payload === '' || $signature === '' || $signingSecret === '') {
            return false;
        }
        if (strlen($signature) !== self::SIGNATURE_LENGTH) {
            return false;
        }

        // hash_equals, never ===: the comparison is against a secret-derived
        // value, and a short-circuiting compare leaks how much of a forged
        // signature was right.
        return hash_equals(hash_hmac('sha256', $payload, $signingSecret), strtolower($signature));
    }
}
