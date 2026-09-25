<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\DTOs\MailtrapStream;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Transport\MailtrapTransport;
use Tests\Unit\Plugins\Mail\Fakes\FakeHttpClient;

#[CoversClass(MailtrapTransport::class)]
#[CoversClass(MailtrapSettings::class)]
#[CoversClass(MailtrapStream::class)]
final class MailtrapTransportTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function transport(
        MailtrapStream $stream = MailtrapStream::Transactional,
        ?int $inboxId = null,
    ): MailtrapTransport {
        return new MailtrapTransport(
            $this->http,
            new MailtrapSettings('tok-123', $stream, $inboxId, timeout: 7),
        );
    }

    private function message(): Message
    {
        return Message::make()->from('no-reply@shop.test')->to('customer@example.com')->subject('x')->text('y');
    }

    public function test_transactional_stream_posts_to_the_send_host_with_a_bearer_token(): void
    {
        $this->http->willRespond(200, ['success' => true, 'message_ids' => ['id-1']]);

        $ids = $this->transport()->sendMessage($this->message());

        $sent = $this->http->last();
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://send.api.mailtrap.io/api/send', $sent['url']);
        $this->assertSame('Bearer tok-123', $sent['headers']['Authorization']);
        $this->assertSame('application/json', $sent['headers']['Content-Type']);
        $this->assertSame(7, $sent['timeout']);
        $this->assertSame('customer@example.com', $sent['body']['to'][0]['email']);
        $this->assertSame(['id-1'], $ids);
    }

    public function test_bulk_stream_is_a_different_host_not_a_payload_flag(): void
    {
        $this->http->willRespond(200, ['success' => true, 'message_ids' => []]);

        $this->transport(MailtrapStream::Bulk)->sendMessage($this->message());

        $this->assertSame('https://bulk.api.mailtrap.io/api/send', $this->http->last()['url']);
    }

    public function test_sandbox_stream_puts_the_inbox_id_in_the_path(): void
    {
        $this->http->willRespond(200, ['success' => true, 'message_ids' => []]);

        $this->transport(MailtrapStream::Sandbox, 4242)->sendMessage($this->message());

        $this->assertSame('https://sandbox.api.mailtrap.io/api/send/4242', $this->http->last()['url']);
    }

    public function test_sandbox_without_an_inbox_id_fails_when_configured_not_when_sending(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('inbox id');

        new MailtrapSettings('tok', MailtrapStream::Sandbox);
    }

    public function test_an_http_error_reports_the_api_error_list(): void
    {
        $this->http->willRespond(401, ['success' => false, 'errors' => ['Incorrect API token']]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->transport()->sendMessage($this->message());
    }

    public function test_a_200_carrying_success_false_is_a_failure_not_a_delivery(): void
    {
        $this->http->willRespond(200, ['success' => false, 'errors' => ['Sending domain not verified']]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Sending domain not verified');

        $this->transport()->sendMessage($this->message());
    }

    public function test_a_non_json_error_page_still_produces_a_useful_message(): void
    {
        $this->http->willRespondRaw(502, '<html>Bad gateway</html>');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Bad gateway');

        $this->transport()->sendMessage($this->message());
    }

    public function test_a_network_failure_becomes_a_mail_exception_so_retries_and_fallbacks_see_it(): void
    {
        $this->http->willThrow(new \RuntimeException('cURL: could not resolve host'));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('could not resolve host');

        $this->transport()->sendMessage($this->message());
    }

    public function test_the_api_token_never_appears_in_a_failure_message(): void
    {
        $this->http->willRespond(401, ['success' => false, 'errors' => ['Incorrect API token']]);

        try {
            $this->transport()->sendMessage($this->message());
            $this->fail('expected a MailException');
        } catch (MailException $e) {
            $this->assertStringNotContainsString('tok-123', $e->getMessage());
        }
    }

    public function test_raw_mime_is_refused_with_a_pointer_to_smtp(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAIL_TRANSPORT=smtp');

        $this->transport()->send('no-reply@shop.test', ['a@example.com'], 'Subject: x');
    }

    public function test_batch_posts_once_and_returns_ids_per_entry(): void
    {
        $this->http->willRespond(200, ['success' => true, 'responses' => [
            ['success' => true, 'message_ids' => ['a']],
            ['success' => true, 'message_ids' => ['b']],
        ]]);

        $ids = $this->transport()->sendBatch([
            $this->message(),
            Message::make()->from('no-reply@shop.test')->to('two@example.com')->subject('x')->text('y'),
        ]);

        $this->assertCount(1, $this->http->sent);
        $this->assertSame('https://send.api.mailtrap.io/api/batch', $this->http->last()['url']);
        $this->assertSame([['a'], ['b']], $ids);
    }

    public function test_a_failed_batch_entry_is_reported_even_though_the_call_returned_200(): void
    {
        $this->http->willRespond(200, ['success' => true, 'responses' => [
            ['success' => true, 'message_ids' => ['a']],
            ['success' => false, 'errors' => ['Invalid recipient']],
        ]]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('#1: Invalid recipient');

        $this->transport()->sendBatch([$this->message(), $this->message()]);
    }

    /**
     * The entries that succeeded are already delivered, so a caller who catches
     * this and re-sends the batch mails those recipients twice. The exception
     * therefore has to carry what went out — reporting only the failure text
     * leaves no way to tell the delivered from the undelivered.
     */
    public function test_a_partially_failed_batch_reports_which_entries_were_delivered(): void
    {
        $this->http->willRespond(200, ['success' => true, 'responses' => [
            ['success' => true,  'message_ids' => ['id-a']],
            ['success' => false, 'errors' => ['Invalid recipient']],
            ['success' => true,  'message_ids' => ['id-c']],
        ]]);

        try {
            $this->transport()->sendBatch([$this->message(), $this->message(), $this->message()]);
            $this->fail('a partially failed batch must not be reported as sent');
        } catch (MailException $e) {
            $this->assertSame([0 => ['id-a'], 2 => ['id-c']], $e->context['sent']);
            $this->assertSame([1], $e->context['failed_indexes']);
            $this->assertStringContainsString('2 of 3 entries were accepted', $e->getMessage());
        }
    }

    public function test_an_http_failure_carries_its_status_so_permanent_faults_are_distinguishable(): void
    {
        $this->http->willRespond(422, ['errors' => ['Sending domain is not verified']]);

        try {
            $this->transport()->sendMessage($this->message());
            $this->fail('a 422 must not be reported as sent');
        } catch (MailException $e) {
            $this->assertSame(422, $e->status);
            // Retrying an unverified domain spends the whole retry budget on a
            // request the API has already refused.
            $this->assertTrue($e->isPermanent());
        }
    }

    public function test_a_server_error_and_a_network_fault_are_both_treated_as_worth_retrying(): void
    {
        $this->http->willRespond(503, ['errors' => ['temporarily unavailable']]);

        try {
            $this->transport()->sendMessage($this->message());
            $this->fail('a 503 must not be reported as sent');
        } catch (MailException $e) {
            $this->assertSame(503, $e->status);
            $this->assertFalse($e->isPermanent());
        }

        // A fault that never reached an HTTP response has status 0, which says
        // nothing about whether it recurs — so it must NOT be called permanent,
        // or a connection blip would silently drop real mail.
        $this->http->willThrow(new \RuntimeException('connection refused'));

        try {
            $this->transport()->sendMessage($this->message());
            $this->fail('a transport fault must not be reported as sent');
        } catch (MailException $e) {
            $this->assertSame(0, $e->status);
            $this->assertFalse($e->isPermanent());
        }
    }

    public function test_a_batch_over_the_api_limit_is_refused_rather_than_split(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('at most 500');

        $this->transport()->sendBatch(array_fill(0, 501, $this->message()));
    }

    public function test_settings_redact_the_token_from_debug_output(): void
    {
        $settings = new MailtrapSettings('super-secret', MailtrapStream::Bulk);

        $this->assertStringNotContainsString('super-secret', print_r($settings->__debugInfo(), true));
        $this->assertStringNotContainsString('super-secret', (string) $settings);
        $this->assertSame('bulk.api.mailtrap.io', $settings->host());
    }

    public function test_an_unknown_stream_names_the_accepted_set(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('transactional, bulk, sandbox');

        MailtrapStream::parse('production');
    }
}
