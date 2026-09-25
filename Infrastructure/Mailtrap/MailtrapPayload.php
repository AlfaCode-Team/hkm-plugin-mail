<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Mailtrap;

use Plugins\Mail\Domain\Address;
use Plugins\Mail\Domain\Attachment;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Domain\Priority;

/**
 * Maps a {@see Message} onto the JSON body the Mailtrap Send API expects.
 *
 * This is the whole reason the API transport is not "the MIME transport with a
 * different socket": Mailtrap's `/api/send` takes a STRUCTURED document, not a
 * message. It builds the MIME itself — which is also why DKIM signing does not
 * apply on this path (Mailtrap signs with the keys registered for the sending
 * domain; a locally-added DKIM-Signature header has nothing to sign over).
 *
 * The mapping mirrors the official `mailtrap/mailtrap-php` SDK's
 * `EmailPayloadTrait`, minus its Symfony Mime coupling:
 *
 *   from / to / cc / bcc     {"email": "...", "name": "..."}; `name` omitted when empty
 *   reply_to                 a SINGLE object — see replyTo() below
 *   subject / text / html    plain strings
 *   attachments              base64 `content`, `type`, `filename`, `disposition`
 *                            and, for inline parts, `content_id`
 *   headers                  a map of extra RFC 5322 headers
 *   category                 ONE label (the API rejects more than one)
 *   custom_variables         a flat map echoed back on webhooks
 *   template_uuid / _variables   a template stored at Mailtrap
 *
 * Everything optional is OMITTED rather than sent empty, because the API
 * validates presence, not emptiness: `"subject": ""` is a present subject and
 * is rejected alongside a template, where an absent one is fine.
 */
final class MailtrapPayload
{
    /**
     * A complete send: From and at least one recipient are required.
     *
     * @return array<string,mixed>
     */
    public function build(Message $message): array
    {
        $from = $message->getFrom();

        if ($from === null) {
            throw new MailException('Mailtrap: a message must have a From address.');
        }
        if ($message->recipientEmails() === []) {
            throw new MailException('Mailtrap: a message must have at least one recipient.');
        }

        return $this->fields($message, ['from' => $this->address($from)]);
    }

    /**
     * A reply or forward on an INBOUND message.
     *
     * Neither From nor a recipient is required: Mailtrap addresses a reply from
     * the message being answered, and fills the sender from the inbound inbox's
     * own domain. Requiring them here — as {@see self::build()} does — would
     * make the ordinary case (`reply($id, $mailer->message()->text('thanks'))`)
     * impossible to express.
     *
     * @return array<string,mixed>
     */
    public function buildReply(Message $message): array
    {
        $from = $message->getFrom();

        return $this->fields($message, $from === null ? [] : ['from' => $this->address($from)]);
    }

    /**
     * Build the body for `/api/batch`: one shared `base` plus one entry per
     * recipient, sent as a single request.
     *
     * Mailtrap requires the base to carry NO recipients — that is the point of
     * the endpoint, the per-request entries supply them — so a base that names
     * any is refused here rather than at the API. The per-recipient entries are
     * ordinary payloads and may each override anything in the base.
     *
     * WHAT AN ENTRY MUST CARRY, and why it is not simply {@see self::build()}:
     * the base exists to hold everything the entries share, `from` very much
     * included. The endpoint's whole documented shape is a base carrying
     * from/subject/body and entries carrying nothing but `to`. Requiring a From
     * on every entry would make that shape — the one in Mailtrap's own
     * examples — impossible to express, so From is required per entry only
     * when no base supplies one. A recipient is always required, because the
     * base is forbidden from carrying one and mail with no destination is not
     * a thing the API can do anything with.
     *
     * @param list<Message> $messages one per recipient (max 500 per the API)
     * @return array<string,mixed>
     */
    public function batch(array $messages, ?Message $base = null): array
    {
        if ($messages === []) {
            throw new MailException('Mailtrap: a batch needs at least one message.');
        }

        $body     = [];
        $baseFrom = false;

        if ($base !== null) {
            if ($base->getTo() !== [] || $base->getCc() !== [] || $base->getBcc() !== []) {
                throw new MailException(
                    'Mailtrap: the batch base message may not carry to/cc/bcc — '
                    . 'recipients belong on the per-message entries.',
                );
            }

            $from     = $base->getFrom();
            $baseFrom = $from !== null;
            $body['base'] = $this->fields($base, $baseFrom ? ['from' => $this->address($from)] : []);
        }

        $body['requests'] = array_map(
            fn(Message $message): array => $this->entry($message, $baseFrom),
            array_values($messages),
        );

        return $body;
    }

    /**
     * One `requests[]` entry.
     *
     * @param bool $baseSuppliesFrom the batch base already carries a From, so
     *                               this entry does not have to
     * @return array<string,mixed>
     */
    private function entry(Message $message, bool $baseSuppliesFrom): array
    {
        $from = $message->getFrom();

        if ($from === null && !$baseSuppliesFrom) {
            throw new MailException(
                'Mailtrap: a batch entry needs a From address, because the batch has no base message '
                . 'carrying one. Either set from() on each message, or pass a base message that does.',
            );
        }
        if ($message->recipientEmails() === []) {
            throw new MailException(
                'Mailtrap: every batch entry must name at least one recipient — the base message '
                . 'cannot carry them, so an entry without one would have nowhere to go.',
            );
        }

        return $this->fields($message, $from === null ? [] : ['from' => $this->address($from)]);
    }

    // ── shared field mapping ─────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload seeded with `from` when there is one
     * @return array<string,mixed>
     */
    private function fields(Message $message, array $payload): array
    {
        $this->assertNoEnvelopeOverride($message);

        foreach (['to' => $message->getTo(), 'cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $field => $addresses) {
            if ($addresses !== []) {
                $payload[$field] = array_map($this->address(...), $addresses);
            }
        }

        // The API models reply_to as ONE mailbox while a Message may hold
        // several (it also renders a MIME Reply-To list). Sending an array here
        // is a 400, and silently dropping the extras would be worse than saying
        // so: a reply going to the wrong address is not visible from the send.
        $replyTo = $message->getReplyTo();
        if ($replyTo !== []) {
            if (count($replyTo) > 1) {
                throw new MailException(
                    'Mailtrap: the API accepts a single Reply-To address, but the message has '
                    . count($replyTo) . '.',
                );
            }
            $payload['reply_to'] = $this->address($replyTo[0]);
        }

        if ($message->hasTemplate()) {
            $this->assertTemplateStandsAlone($message);

            $payload['template_uuid'] = $message->getTemplateUuid();

            if ($message->getTemplateVariables() !== []) {
                $payload['template_variables'] = $message->getTemplateVariables();
            }
        } else {
            if ($message->getSubject() !== '') {
                $payload['subject'] = $message->getSubject();
            }
            if ($message->getText() !== '') {
                $payload['text'] = $message->getText();
            }
            if ($message->getHtml() !== '') {
                $payload['html'] = $message->getHtml();
            }
            if ($message->getCategory() !== null) {
                $payload['category'] = $message->getCategory();
            }
        }

        if ($message->getAttachments() !== []) {
            $payload['attachments'] = array_map($this->attachment(...), $message->getAttachments());
        }

        $headers = $this->headers($message);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        if ($message->getCustomVariables() !== []) {
            $payload['custom_variables'] = $message->getCustomVariables();
        }

        return $payload;
    }

    /**
     * Refuse a templated message that also carries content of its own.
     *
     * Mailtrap's `/api/send` body is a `oneOf` over four schemas, and the
     * template one (`EmailFromTemplate`: from + template_uuid) has no subject,
     * text, html or category member at all — the stored template supplies them.
     *
     * The tempting move is to drop those fields silently and send a valid
     * request. This does not do that, for the same reason
     * {@see self::assertNoEnvelopeOverride()} does not: a caller who set a
     * subject believes recipients will see it, and a send that quietly
     * substitutes the template's own subject is indistinguishable from a
     * working one until somebody reads the mail. Failing here costs one
     * exception at the call site; failing silently costs a wrong subject line
     * on every message in the run.
     */
    private function assertTemplateStandsAlone(Message $message): void
    {
        $conflicts = [];

        if ($message->getSubject() !== '') {
            $conflicts[] = 'subject()';
        }
        if ($message->getText() !== '') {
            $conflicts[] = 'text()';
        }
        if ($message->getHtml() !== '') {
            $conflicts[] = 'html()';
        }
        if ($message->getCategory() !== null) {
            $conflicts[] = 'category()';
        }

        if ($conflicts !== []) {
            throw new MailException(sprintf(
                'Mailtrap: template() cannot be combined with %s — the stored template supplies the '
                . 'subject, the bodies and the category, and the API rejects a request carrying both. '
                . 'Remove %s, or drop template() and send the content inline.',
                implode(', ', $conflicts),
                count($conflicts) === 1 ? 'it' : 'them',
            ));
        }
    }

    /**
     * Refuse a message whose envelope this API cannot express.
     *
     * `sender()` and `returnPath()` set the SMTP envelope — who bounces go back
     * to, and who is really sending on the From address's behalf. The Mailtrap
     * Send API has no field for either: it builds the MIME and owns the
     * envelope itself.
     *
     * Dropping them quietly is the worse option by a distance. A return path is
     * set precisely so bounces reach a mailbox that processes them, and losing
     * it is invisible — the mail sends, the bounces go somewhere else, and the
     * suppression list nobody is updating grows. So this fails, at the call,
     * naming the transport that does honour it.
     */
    private function assertNoEnvelopeOverride(Message $message): void
    {
        $set = [];

        if ($message->getSender() !== null) {
            $set[] = 'sender()';
        }
        if ($message->getReturnPath() !== null) {
            $set[] = 'returnPath()';
        }

        if ($set !== []) {
            throw new MailException(sprintf(
                'Mailtrap: %s set the SMTP envelope, and the Sending API has no field for it — Mailtrap '
                . 'builds the MIME and owns the envelope. Remove %s from the message, or deliver it through '
                . 'an SMTP transport (MAIL_TRANSPORT=smtp), which does honour %s.',
                implode(' and ', $set),
                count($set) === 1 ? 'it' : 'them',
                count($set) === 1 ? 'it' : 'them',
            ));
        }
    }

    /** @return array{email: string, name?: string} */
    private function address(Address $address): array
    {
        $mapped = ['email' => $address->email];

        if ($address->name !== '') {
            $mapped['name'] = $address->name;
        }

        return $mapped;
    }

    /** @return array<string,string> */
    private function attachment(Attachment $attachment): array
    {
        $mapped = [
            'content'     => base64_encode($attachment->contents()),
            'type'        => $attachment->mimeType,
            'filename'    => $attachment->name,
            'disposition' => $attachment->inline ? 'inline' : 'attachment',
        ];

        if ($attachment->inline) {
            // What the HTML references as `cid:logo`. The SDK reuses the
            // filename here; this plugin has a real Content-ID on the
            // attachment, so an embed() whose cid differs from its filename
            // keeps working instead of rendering a broken image.
            $mapped['content_id'] = $attachment->cid;
        }

        return $mapped;
    }

    /**
     * Headers that have no first-class field in the payload.
     *
     * Priority and the read receipt are ordinary RFC 5322 headers that the MIME
     * builder emits, so they are passed through here too — otherwise a message
     * would quietly lose them by being sent through the API instead of SMTP.
     *
     * @return array<string,string>
     */
    private function headers(Message $message): array
    {
        $headers = $message->getHeaders();

        $priority = $message->getPriority();
        if ($priority !== Priority::Normal) {
            $headers['X-Priority'] = $priority->value . ' (' . $priority->label() . ')';
        }

        $receipt = $message->getConfirmReadingTo();
        if ($receipt !== null) {
            $headers['Disposition-Notification-To'] = $receipt->toHeader($message->getCharset());
        }

        return $headers;
    }
}
