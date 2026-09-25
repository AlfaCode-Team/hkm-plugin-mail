<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\MailtrapWebhookSignature;

#[CoversClass(MailtrapWebhookSignature::class)]
final class MailtrapWebhookSignatureTest extends TestCase
{
    private const SECRET  = 'signing-secret';
    private const PAYLOAD = '{"events":[{"event":"bounce","email":"a@example.com"}]}';

    private function signature(string $payload = self::PAYLOAD, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public function test_accepts_a_genuine_signature(): void
    {
        $this->assertTrue(MailtrapWebhookSignature::verify(self::PAYLOAD, $this->signature(), self::SECRET));
    }

    public function test_accepts_an_uppercase_hex_digest(): void
    {
        $this->assertTrue(MailtrapWebhookSignature::verify(
            self::PAYLOAD,
            strtoupper($this->signature()),
            self::SECRET,
        ));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $this->assertFalse(MailtrapWebhookSignature::verify(
            '{"events":[{"event":"bounce","email":"attacker@evil.test"}]}',
            $this->signature(),
            self::SECRET,
        ));
    }

    public function test_rejects_a_signature_from_another_secret(): void
    {
        $this->assertFalse(MailtrapWebhookSignature::verify(
            self::PAYLOAD,
            $this->signature(secret: 'someone-elses-secret'),
            self::SECRET,
        ));
    }

    public function test_returns_false_rather_than_throwing_on_anything_that_can_arrive_over_the_wire(): void
    {
        // A controller calls this directly; a throw here would be the reason
        // someone wraps it in a try/catch that lets unsigned requests through.
        $this->assertFalse(MailtrapWebhookSignature::verify(self::PAYLOAD, '', self::SECRET));
        $this->assertFalse(MailtrapWebhookSignature::verify(self::PAYLOAD, 'short', self::SECRET));
        $this->assertFalse(MailtrapWebhookSignature::verify(self::PAYLOAD, str_repeat('z', 64), self::SECRET));
        $this->assertFalse(MailtrapWebhookSignature::verify('', $this->signature(''), self::SECRET));
        $this->assertFalse(MailtrapWebhookSignature::verify(self::PAYLOAD, $this->signature(), ''));
    }

    public function test_a_reserialised_body_does_not_verify(): void
    {
        // The signature is over the RAW bytes — this is the mistake the
        // docblock warns about, pinned so the warning stays true.
        $reserialised = json_encode(json_decode(self::PAYLOAD, true), JSON_PRETTY_PRINT);

        $this->assertFalse(MailtrapWebhookSignature::verify($reserialised, $this->signature(), self::SECRET));
    }
}
