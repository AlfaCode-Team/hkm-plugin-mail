<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\Mailtrap\MailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\Attachment;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\TransportFactory;
use Tests\Unit\Plugins\Mail\Fakes\FakeHttpClient;

/**
 * Regressions for defects found reviewing this plugin's Mailtrap work.
 *
 * Each of these was reachable, reproduced with a proof of concept, and fixed.
 * They are pinned here because every one of them fails SILENTLY — a leaked
 * token looks like a normal debug dump, an injected header looks like a
 * delivered mail, a redirected host looks like a working request.
 */
#[CoversClass(MailtrapHttp::class)]
#[CoversClass(Message::class)]
#[CoversClass(Attachment::class)]
final class MailSecurityTest extends TestCase
{
    private const TOKEN = 'SECRET-TOKEN-abc123';

    // ── 1. credential exposure in debug output ───────────────────────────────

    /**
     * MailtrapHttp holds the LIVE token and hangs off every resource client, so
     * a print_r() of any of them printed it in the clear — defeating the
     * redaction MailtrapSettings and SmtpSettings already had.
     */
    public function test_the_api_token_is_redacted_from_every_debug_surface(): void
    {
        $api = new MailtrapApi(new MailtrapHttp(new FakeHttpClient(), self::TOKEN));

        foreach ([
            'the http client'  => new MailtrapHttp(new FakeHttpClient(), self::TOKEN),
            'the facade'       => $api,
            'a resource client'=> $api->accounts(),
            'a scoped client'  => $api->suppressions(1),
            'the settings DTO' => new MailtrapSettings(self::TOKEN),
        ] as $label => $subject) {
            $this->assertStringNotContainsString(self::TOKEN, print_r($subject, true), "print_r: {$label}");

            ob_start();
            var_dump($subject);
            $this->assertStringNotContainsString(self::TOKEN, (string) ob_get_clean(), "var_dump: {$label}");
        }
    }

    // ── 2. host validation (credential redirection) ──────────────────────────

    /**
     * The host is concatenated into the request URL and the bearer token goes
     * with whatever that resolves to. MailtrapSettings refused these already,
     * but TransportFactory::mailtrapApiFromArray() built MailtrapHttp straight
     * from MAILTRAP_HOST, bypassing it.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function hostileHosts(): array
    {
        return [
            ['attacker.test/collect',       'a path sends the token to another origin'],
            ['mailtrap.io@attacker.test',   'userinfo — the real host is what follows the @'],
            ['mailtrap.io:443@attacker.test', 'userinfo with a port'],
            ["mailtrap.io\r\nX-Evil: 1",    'CRLF'],
            ["mailtrap.io\nX-Evil: 1",      'bare LF'],
            ['https://attacker.test',       'a scheme'],
            ['mailtrap.io ',                'trailing whitespace'],
            ['',                            'empty'],
        ];
    }

    #[DataProvider('hostileHosts')]
    public function test_a_hostile_host_is_refused_at_the_choke_point(string $host, string $why): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('invalid host');

        new MailtrapHttp(new FakeHttpClient(), 'tok', $host);
    }

    public function test_the_config_path_cannot_bypass_host_validation(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('invalid host');

        (new TransportFactory(new FakeHttpClient()))
            ->mailtrapApiFromArray(['token' => 'tok', 'host' => 'attacker.test/collect']);
    }

    public function test_a_legitimate_host_with_a_port_still_works(): void
    {
        $this->assertSame('mailtrap.io:443', (new MailtrapHttp(new FakeHttpClient(), 'tok', 'mailtrap.io:443'))->host());
        $this->assertSame('mailtrap.io', (new MailtrapHttp(new FakeHttpClient(), 'tok'))->host());
    }

    public function test_a_control_character_in_the_token_is_refused(): void
    {
        $this->expectException(MailException::class);

        new MailtrapHttp(new FakeHttpClient(), "tok\r\nX-Evil: 1");
    }

    // ── 3 & 4. MIME header injection ─────────────────────────────────────────

    /** Only a REAL top-level header line counts — a substring in a value does not. */
    private function hasTopLevelHeader(Message $message, string $pattern): bool
    {
        foreach ((new MimeBuilder())->build($message)['headers'] as $header) {
            if (preg_match($pattern, $header) === 1) {
                return true;
            }
        }

        return false;
    }

    private function base(): Message
    {
        return Message::make()->from('a@shop.test')->to('victim@example.com')->subject('hi')->text('body');
    }

    /**
     * The charset is written into `Content-Type: …; charset=…`, so a CRLF in it
     * smuggled a genuine top-level `Bcc:` past the guards on every other field —
     * a silent extra recipient, which is exactly what those guards exist for.
     *
     * It mattered little while the charset came only from config. It mattered
     * once Message::fromArray() started rebuilding messages from QUEUE payloads.
     */
    public function test_a_crlf_charset_cannot_smuggle_a_top_level_header(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Invalid charset');

        $this->base()->charset("UTF-8\r\nBcc: attacker@evil.test");
    }

    public function test_the_same_charset_is_refused_when_it_arrives_from_the_queue(): void
    {
        $this->expectException(MailException::class);

        Message::fromArray([
            'from'    => ['email' => 'a@shop.test', 'name' => ''],
            'to'      => [['email' => 'victim@example.com', 'name' => '']],
            'charset' => "UTF-8\r\nBcc: attacker@evil.test",
        ]);
    }

    public function test_ordinary_charsets_still_pass(): void
    {
        foreach (['UTF-8', 'ISO-8859-1', 'windows-1252', 'us-ascii', 'Shift_JIS'] as $charset) {
            $this->assertSame($charset, $this->base()->charset($charset)->getCharset());
        }
    }

    /**
     * The attachment mime type is concatenated into the part's `Content-Type:`
     * exactly as name and cid are into theirs — and was the only one of the
     * three without a guard.
     */
    public function test_a_crlf_attachment_mime_type_is_refused(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('control characters');

        $this->base()->attachData('x', 'ok.txt', "text/plain\r\nBcc: attacker@evil.test");
    }

    public function test_the_same_mime_type_is_refused_when_it_arrives_from_the_queue(): void
    {
        $this->expectException(MailException::class);

        Message::fromArray([
            'from'        => ['email' => 'a@shop.test', 'name' => ''],
            'to'          => [['email' => 'victim@example.com', 'name' => '']],
            'attachments' => [[
                'name' => 'ok.txt', 'mime_type' => "text/plain\r\nBcc: attacker@evil.test",
                'inline' => false, 'cid' => '', 'data' => base64_encode('x'),
            ]],
        ]);
    }

    // ── the guards that were already there, pinned ───────────────────────────

    /** @return array<string,array{0:\Closure}> */
    public static function existingGuards(): array
    {
        $probe = "X\r\nBcc: attacker@evil.test";

        return [
            'recipient address'   => [fn() => Message::make()->to("v@e.test{$probe}")],
            'recipient name'      => [fn() => Message::make()->to('v@e.test', $probe)],
            'from address'        => [fn() => Message::make()->from("a@b.test{$probe}")],
            'reply-to name'       => [fn() => Message::make()->replyTo('a@b.test', $probe)],
            'return path'         => [fn() => Message::make()->returnPath("a@b.test{$probe}")],
            'custom header name'  => [fn() => Message::make()->header($probe, 'v')],
            'custom header value' => [fn() => Message::make()->header('X-T', $probe)],
            'attachment name'     => [fn() => Message::make()->attachData('x', $probe)],
            'attachment cid'      => [fn() => Message::make()->embedData('x', $probe)],
            'category'            => [fn() => Message::make()->category($probe)],
            'template uuid'       => [fn() => Message::make()->template($probe)],
        ];
    }

    #[DataProvider('existingGuards')]
    public function test_control_characters_are_refused_on_every_header_bearing_field(\Closure $call): void
    {
        $this->expectException(MailException::class);

        $call();
    }

    public function test_the_subject_strips_control_characters_rather_than_throwing(): void
    {
        // Deliberately different: a subject is free text a user typed, so it is
        // sanitised instead of rejected. Assert no header line is produced.
        $message = $this->base()->subject("Order\r\nBcc: attacker@evil.test");

        $this->assertFalse($this->hasTopLevelHeader($message, '/^Bcc:/i'));
    }

    public function test_bcc_recipients_never_reach_the_headers(): void
    {
        $message = $this->base()->bcc('hidden@example.com');

        $this->assertFalse($this->hasTopLevelHeader($message, '/^Bcc:/i'));
        $this->assertContains('hidden@example.com', $message->recipientEmails());
    }
}
