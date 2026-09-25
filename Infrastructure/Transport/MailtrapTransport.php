<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload;

/**
 * Delivers through the Mailtrap Sending API (`POST /api/send`, `/api/batch`).
 *
 * The stream — transactional, bulk or sandbox — is the HOST, carried by
 * {@see MailtrapSettings}; sandbox additionally puts the inbox id in the path.
 * The body is built by {@see MailtrapPayload}, which is the port of the
 * official SDK's payload mapping.
 *
 * Outbound HTTP goes through {@see MailtrapHttp} — the same client the
 * management API uses — so authentication, timeouts and error translation are
 * decided in ONE place rather than drifting between the two. That client in
 * turn goes through the kernel {@see HttpClientPort}, never a cURL handle
 * opened here, which is what lets a test drive this class with a fake client
 * instead of a network.
 *
 * WHAT THIS PATH DOES NOT DO, deliberately:
 *   - No DKIM. Mailtrap signs with the keys registered for the sending domain;
 *     the local signer has no MIME to sign and its header would be stripped.
 *   - No `Transport::send()`. There is no raw-MIME endpoint on this API — see
 *     the method for what to use instead.
 */
final class MailtrapTransport implements MessageTransport
{
    private readonly MailtrapHttp $api;

    public function __construct(
        HttpClientPort $http,
        private readonly MailtrapSettings $settings,
        private readonly MailtrapPayload $payload = new MailtrapPayload(),
    ) {
        // The SENDING host, not mailtrap.io: the stream is the host, and a
        // send posted to the management host 404s.
        $this->api = new MailtrapHttp($http, $settings->apiToken, $settings->host(), $settings->timeout);
    }

    /**
     * Not supported: the Sending API has no raw-MIME channel.
     *
     * Everything inside this plugin reaches an API transport through
     * {@see self::sendMessage()}, so this is only hit by code that built MIME
     * itself and wants it delivered verbatim. That is a real need (a stored
     * `.eml`, a re-send of an archived message) and Mailtrap serves it over
     * SMTP, not over this API — hence the pointer rather than a silent
     * best-effort reconstruction, which would drop attachments and headers in
     * ways nobody would notice until a customer did.
     */
    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        throw new MailException(
            'The Mailtrap API transport delivers structured messages and has no raw-MIME endpoint. '
            . 'Send a Message through MailerContract::dispatch(), or set MAIL_TRANSPORT=smtp with '
            . "Mailtrap's SMTP host to deliver pre-built MIME.",
        );
    }

    /** @return list<string> */
    public function sendMessage(Message $message): array
    {
        $body = $this->post($this->settings->sendPath(), $this->payload->build($message));

        return $this->messageIds($body);
    }

    /** @return list<list<string>> */
    public function sendBatch(array $messages, ?Message $base = null): array
    {
        $messages = array_values($messages);

        // The API caps a batch at 500. Splitting silently here would turn one
        // atomic call into several with no way for the caller to know which
        // half failed, so the limit is the caller's to respect.
        if (count($messages) > 500) {
            throw new MailException(
                'Mailtrap: a batch may hold at most 500 messages, got ' . count($messages) . '.',
            );
        }

        $body = $this->post($this->settings->batchPath(), $this->payload->batch($messages, $base));

        // Batch answers per entry: {"success": true, "responses": [ ... ]}. A
        // 200 therefore does NOT mean every message was accepted — an entry may
        // carry its own errors, and reporting the batch as sent would lose them.
        $responses = $body['responses'] ?? null;
        if (!is_array($responses)) {
            return [];
        }

        $ids    = [];
        $failed = [];
        foreach (array_values($responses) as $index => $response) {
            $response = (array) $response;

            if (($response['success'] ?? false) !== true) {
                $failed[] = '#' . $index . ': ' . MailtrapHttp::errorText($response);
                continue;
            }

            $ids[$index] = $this->messageIds($response);
        }

        if ($failed !== []) {
            // A partial failure is NOT recoverable by re-sending the batch: the
            // entries that succeeded are already delivered, and a naive retry
            // mails those recipients a second time. So the exception carries
            // what went out, keyed by the caller's own entry index, and the
            // indexes that did not. Throwing with only the error text — which
            // is what this did — left a caller with 497 delivered messages and
            // no way to know which 3 to resend.
            throw new MailException(
                'Mailtrap batch: ' . implode('; ', $failed)
                . sprintf(' (%d of %d entries were accepted and ARE delivered; '
                    . 'see the exception context before resending anything)', count($ids), count($responses)),
                context: [
                    'sent'           => $ids,
                    'failed_indexes' => array_values(array_diff(array_keys($responses), array_keys($ids))),
                ],
            );
        }

        return array_values($ids);
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $body
     * @return array<string,mixed> the decoded response
     */
    private function post(string $path, array $body): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = $this->api->post($path, $body);

        return $decoded;
    }

    /**
     * @param  array<string,mixed> $body
     * @return list<string>
     */
    private function messageIds(array $body): array
    {
        return array_values(array_filter(
            array_map(
                static fn(mixed $id): string => is_scalar($id) ? (string) $id : '',
                (array) ($body['message_ids'] ?? []),
            ),
            static fn(string $id): bool => $id !== '',
        ));
    }
}
