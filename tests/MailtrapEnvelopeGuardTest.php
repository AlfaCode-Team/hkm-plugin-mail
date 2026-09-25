<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;

/**
 * `sender()` and `returnPath()` set the SMTP ENVELOPE, which the Mailtrap
 * Sending API has no field for — it builds the MIME and owns the envelope.
 *
 * Dropping them quietly would be invisible and expensive: a return path is set
 * so bounces reach a mailbox that processes them, and losing it means the mail
 * still sends while the bounces go somewhere nobody reads. So the API path
 * refuses the message instead.
 */
#[CoversClass(MailtrapPayload::class)]
final class MailtrapEnvelopeGuardTest extends TestCase
{
    private function message(): Message
    {
        return Message::make()->from('no-reply@shop.test')->to('customer@example.com')->subject('x')->text('y');
    }

    public function test_a_return_path_is_refused_with_a_pointer_to_smtp(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAIL_TRANSPORT=smtp');

        (new MailtrapPayload())->build($this->message()->returnPath('bounces@shop.test'));
    }

    public function test_a_distinct_sender_is_refused(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('sender()');

        (new MailtrapPayload())->build($this->message()->sender('bounces@shop.test'));
    }

    public function test_both_are_named_when_both_are_set(): void
    {
        try {
            (new MailtrapPayload())->build(
                $this->message()->sender('bounces@shop.test')->returnPath('bounces@shop.test'),
            );
            $this->fail('expected a MailException');
        } catch (MailException $e) {
            $this->assertStringContainsString('sender()', $e->getMessage());
            $this->assertStringContainsString('returnPath()', $e->getMessage());
        }
    }

    public function test_the_same_message_is_fine_on_the_mime_path(): void
    {
        // The guard is the API payload's, not the Message's — SMTP honours both.
        $built = (new MimeBuilder())->build(
            $this->message()->sender('bounces@shop.test')->returnPath('bounces@shop.test'),
        );

        $this->assertNotEmpty(array_filter(
            $built['headers'],
            static fn(string $h): bool => str_starts_with($h, 'Sender: '),
        ));
    }

    public function test_a_message_without_an_envelope_override_passes(): void
    {
        $built = (new MailtrapPayload())->build($this->message());

        $this->assertSame('no-reply@shop.test', $built['from']['email']);
    }
}
