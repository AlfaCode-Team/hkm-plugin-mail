<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\Application\Jobs\SendMailJob;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Domain\Attachment;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Domain\Priority;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;
use Tests\Unit\Plugins\Mail\Fakes\RecordingMessageTransport;

/**
 * The seam between the Mailer, the queue and the worker when the configured
 * transport takes MESSAGES rather than MIME.
 */
#[CoversClass(Mailer::class)]
#[CoversClass(SendMailJob::class)]
#[CoversClass(Message::class)]
#[CoversClass(Attachment::class)]
final class StructuredDeliveryTest extends TestCase
{
    private RecordingMessageTransport $transport;

    /** @var list<array{class: string, payload: array<string,mixed>, queue: string}> */
    private array $pushed = [];

    protected function setUp(): void
    {
        $this->transport = new RecordingMessageTransport();
        $this->pushed    = [];
    }

    private function mailer(bool $withQueue = false): Mailer
    {
        return new Mailer(
            transport: $this->transport,
            mime:      new MimeBuilder(),
            queue:     $withQueue ? $this->queue() : null,
            fromEmail: 'no-reply@shop.test',
            fromName:  'Shop',
        );
    }

    // ── dispatch ─────────────────────────────────────────────────────────────

    public function test_dispatch_hands_the_message_to_an_api_transport_instead_of_building_mime(): void
    {
        $this->mailer()->dispatch(
            Message::make()->to('customer@example.com')->subject('Welcome')->text('hi'),
        );

        $this->assertCount(1, $this->transport->messages);
        $this->assertSame('Welcome', $this->transport->last()->getSubject());
    }

    public function test_the_configured_default_from_is_applied_on_the_api_path_too(): void
    {
        // It used to be filled in by compile(), which an API transport never
        // reaches — a message with no From would have gone out senderless.
        $this->mailer()->dispatch(Message::make()->to('customer@example.com')->subject('x')->text('y'));

        $this->assertSame('no-reply@shop.test', $this->transport->last()->getFrom()->email);
        $this->assertSame('Shop', $this->transport->last()->getFrom()->name);
    }

    public function test_a_mime_transport_is_still_given_mime(): void
    {
        $array  = new ArrayTransport();
        $mailer = new Mailer($array, new MimeBuilder(), fromEmail: 'no-reply@shop.test');

        $mailer->dispatch(Message::make()->to('customer@example.com')->subject('x')->text('y'));

        $this->assertStringContainsString('Subject: x', $array->last()['mime']);
    }

    // ── queue ────────────────────────────────────────────────────────────────

    public function test_an_api_transport_queues_the_message_not_the_mime(): void
    {
        $id = $this->mailer(withQueue: true)->enqueue(
            Message::make()->to('customer@example.com')->subject('Welcome')->text('hi'),
        );

        $this->assertSame('job-1', $id);
        $this->assertSame(Mailer::QUEUE_JOB, $this->pushed[0]['class']);
        $this->assertArrayHasKey('message', $this->pushed[0]['payload']);
        $this->assertArrayNotHasKey('mime', $this->pushed[0]['payload']);
        $this->assertSame('Welcome', $this->pushed[0]['payload']['message']['subject']);
    }

    public function test_a_mime_transport_still_queues_the_built_bytes(): void
    {
        $mailer = new Mailer(new ArrayTransport(), new MimeBuilder(), queue: $this->queue(), fromEmail: 'no-reply@shop.test');

        $mailer->enqueue(Message::make()->to('customer@example.com')->subject('x')->text('y'));

        $this->assertArrayHasKey('mime', $this->pushed[0]['payload']);
        $this->assertArrayNotHasKey('message', $this->pushed[0]['payload']);
    }

    public function test_with_no_queue_bound_an_api_transport_delivers_inline_rather_than_dropping_the_mail(): void
    {
        $this->assertSame('', $this->mailer()->enqueue(
            Message::make()->to('customer@example.com')->subject('x')->text('y'),
        ));
        $this->assertCount(1, $this->transport->messages);
    }

    // ── the worker ───────────────────────────────────────────────────────────

    public function test_the_job_rebuilds_and_sends_a_queued_message(): void
    {
        $this->mailer(withQueue: true)->enqueue(
            Message::make()->to('customer@example.com', 'Cust')->subject('Welcome')->html('<b>hi</b>')
                ->category('onboarding')
                ->customVariable('order_id', 42),
        );

        $result = (new SendMailJob($this->transport))->handle($this->payload($this->pushed[0]['payload']));

        $this->assertTrue($result->isSuccess());
        $sent = $this->transport->last();
        $this->assertSame('Welcome', $sent->getSubject());
        $this->assertSame('onboarding', $sent->getCategory());
        $this->assertSame(['order_id' => '42'], $sent->getCustomVariables());
        $this->assertSame(['customer@example.com'], $sent->recipientEmails());
    }

    public function test_a_structured_payload_on_a_mime_transport_throws_rather_than_silently_discarding_mail(): void
    {
        $this->mailer(withQueue: true)->enqueue(
            Message::make()->to('customer@example.com')->subject('x')->text('y'),
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAIL_TRANSPORT changed');

        (new SendMailJob(new ArrayTransport()))->handle($this->payload($this->pushed[0]['payload']));
    }

    public function test_the_mime_payload_path_is_untouched(): void
    {
        $array = new ArrayTransport();

        $result = (new SendMailJob($array))->handle($this->payload([
            'from'       => 'no-reply@shop.test',
            'recipients' => ['customer@example.com'],
            'mime'       => "Subject: x\r\n\r\nbody",
        ]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('no-reply@shop.test', $array->last()['from']);
    }

    // ── round-trip fidelity ──────────────────────────────────────────────────

    public function test_a_message_survives_the_queue_intact_including_attachment_bytes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mail') . '.csv';
        file_put_contents($file, "a,b\n1,2");

        $original = Message::make()
            ->from('no-reply@shop.test', 'Shop')
            ->sender('bounces@shop.test')
            ->returnPath('bounces@shop.test')
            ->to('customer@example.com', 'Cust')
            ->cc('boss@example.com')
            ->bcc('audit@example.com')
            ->replyTo('support@shop.test')
            ->subject('Welcome')
            ->html('<img src="cid:logo">')
            ->text('hi')
            ->priority(Priority::High)
            ->confirmReadingTo('reads@shop.test')
            ->header('X-Campaign', 'spring')
            ->tag('tenant', 'acme')
            ->category('onboarding')
            ->customVariable('order_id', 42)
            ->embedData('PNG', 'logo', 'logo.png', 'image/png')
            ->attach($file, 'report.csv', 'text/csv');

        $serialised = $original->toArray();

        // The file is gone by the time the worker runs — the bytes must already
        // be in the payload, which is the whole point of materialising them.
        unlink($file);

        $rebuilt = Message::fromArray(json_decode(json_encode($serialised), true));

        $this->assertSame($serialised, $rebuilt->toArray());
        $this->assertSame("a,b\n1,2", $rebuilt->getAttachments()[1]->contents());
        $this->assertSame('bounces@shop.test', $rebuilt->getReturnPath());
        $this->assertSame(Priority::High, $rebuilt->getPriority());
        $this->assertSame('logo', $rebuilt->getAttachments()[0]->cid);
    }

    public function test_a_queued_payload_is_revalidated_so_the_queue_is_not_a_way_round_the_injection_guards(): void
    {
        $this->expectException(MailException::class);

        Message::fromArray([
            'from' => ['email' => 'no-reply@shop.test', 'name' => ''],
            'to'   => [['email' => "victim@example.com\r\nBcc: attacker@evil.test", 'name' => '']],
        ]);
    }

    // ── batch ────────────────────────────────────────────────────────────────

    public function test_batch_goes_to_the_transport_in_one_call_when_it_supports_one(): void
    {
        $base = Message::make()->template('bfa432fd-0000-0000-0000-8493da283a69');

        $ids = $this->mailer()->dispatchBatch([
            Message::make()->to('one@example.com'),
            Message::make()->to('two@example.com'),
        ], $base);

        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(2, $this->transport->batches[0]['messages']);
        // The base gets the configured default From too, or the batch has no sender.
        $this->assertSame('no-reply@shop.test', $this->transport->batches[0]['base']->getFrom()->email);
        $this->assertSame([['batch-0'], ['batch-1']], $ids);
    }

    public function test_batch_falls_back_to_one_send_per_message_on_a_transport_without_one(): void
    {
        $array  = new ArrayTransport();
        $mailer = new Mailer($array, new MimeBuilder(), fromEmail: 'no-reply@shop.test');

        $ids = $mailer->dispatchBatch([
            Message::make()->to('one@example.com')->subject('x')->text('y'),
            Message::make()->to('two@example.com')->subject('x')->text('y'),
        ]);

        $this->assertSame(2, $array->count());
        $this->assertSame([[], []], $ids);
    }

    // ── doubles ──────────────────────────────────────────────────────────────

    private function queue(): QueuePort
    {
        return new class ($this->pushed) implements QueuePort {
            /** @param list<array{class: string, payload: array<string,mixed>, queue: string}> $pushed */
            public function __construct(private array &$pushed) {}

            public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
            {
                $this->pushed[] = ['class' => $jobClass, 'payload' => $payload, 'queue' => $queue];

                return 'job-' . count($this->pushed);
            }

            public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
            {
                return $this->push($jobClass, $payload, $queue);
            }

            public function size(string $queue = 'default'): int { return count($this->pushed); }
            public function pop(string $queue = 'default'): ?JobPayload { return null; }
            public function ack(JobPayload $payload): void {}
            public function release(JobPayload $payload, int $delay = 0): void {}
            public function fail(JobPayload $payload, ?\Throwable $reason = null): void {}
        };
    }

    /** @param array<string,mixed> $data */
    private function payload(array $data): JobPayload
    {
        return new JobPayload('job-1', Mailer::QUEUE_JOB, $data, 'mail', 1, 3, new \DateTimeImmutable(), '');
    }
}
