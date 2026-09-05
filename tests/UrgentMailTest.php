<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;
use Plugins\Mail\Infrastructure\Transport\Transport;

/**
 * Urgent mail: delivered inline instead of queued, up to a concurrency cap.
 *
 * What these lock in is the difference between a verification link arriving
 * now and arriving only if someone happens to be running a worker.
 *
 * The doubles are anonymous classes on purpose: named ones in this file would
 * not comply with the PSR-4 autoload-dev mapping, and composer would log a skip
 * warning for each on every CI run.
 */
#[CoversClass(Mailer::class)]
final class UrgentMailTest extends TestCase
{
    /** Slots currently held, shared between the cache double and its locks. */
    private ArrayObject $held;

    protected function setUp(): void
    {
        $this->held = new ArrayObject();
    }

    public function test_matches_urgent_views_exactly_and_by_prefix(): void
    {
        $mailer = $this->mailer(new ArrayTransport(), $this->cache(), $this->queue());

        self::assertTrue($mailer->isUrgentView('user::emails/verify'));
        self::assertTrue($mailer->isUrgentView('auth::emails/reset'), 'trailing * is a prefix');
        self::assertFalse($mailer->isUrgentView('user::emails/welcome'));
        self::assertFalse($mailer->isUrgentView('auth::emails'), 'a prefix must not match the stem alone');
        self::assertFalse($mailer->isUrgentView('shop::emails/receipt'));
    }

    public function test_urgent_mail_is_delivered_inline_and_never_queued(): void
    {
        $transport = new ArrayTransport();
        $queue     = $this->queue();

        $jobId = $this->mailer($transport, $this->cache(), $queue)
            ->queue('someone@test.local', 'Verify your email address', 'user::emails/verify');

        self::assertSame('', $jobId, 'an empty id means delivered, not queued');
        self::assertCount(1, $transport->messages());
        self::assertSame(0, $queue->size());
    }

    public function test_ordinary_mail_still_queues(): void
    {
        $transport = new ArrayTransport();
        $queue     = $this->queue();

        $jobId = $this->mailer($transport, $this->cache(), $queue)
            ->queue('someone@test.local', 'Newsletter', 'shop::emails/news');

        self::assertNotSame('', $jobId);
        self::assertCount(0, $transport->messages());
        self::assertSame(1, $queue->size());
    }

    public function test_falls_back_to_the_queue_once_every_inline_slot_is_taken(): void
    {
        $transport = new ArrayTransport();
        $queue     = $this->queue();
        // Three sends already in flight in other processes.
        $this->held['mail:inline:0'] = true;
        $this->held['mail:inline:1'] = true;
        $this->held['mail:inline:2'] = true;

        $jobId = $this->mailer($transport, $this->cache(), $queue)
            ->queue('someone@test.local', 'Verify your email address', 'user::emails/verify');

        self::assertNotSame('', $jobId, 'no capacity left, so it must queue');
        self::assertCount(0, $transport->messages());
        self::assertSame(1, $queue->size());
    }

    public function test_a_transport_failure_degrades_to_the_queue_and_frees_the_slot(): void
    {
        $queue  = $this->queue();
        $broken = new class implements Transport {
            public function send(string $from, array $recipients, string $mime): void
            {
                throw new \RuntimeException('smtp down');
            }
        };

        $jobId = $this->mailer($broken, $this->cache(), $queue)
            ->queue('someone@test.local', 'Verify your email address', 'user::emails/verify');

        self::assertNotSame('', $jobId, 'a dead SMTP server must not lose the mail');
        self::assertSame(1, $queue->size());
        self::assertCount(0, $this->held, 'the slot is released even when the send throws');
    }

    public function test_without_a_cache_the_cap_cannot_be_enforced_so_it_queues(): void
    {
        $transport = new ArrayTransport();
        $queue     = $this->queue();

        $jobId = $this->mailer($transport, null, $queue)
            ->queue('someone@test.local', 'Verify your email address', 'user::emails/verify');

        self::assertNotSame('', $jobId);
        self::assertSame(1, $queue->size());
    }

    // ── doubles ──────────────────────────────────────────────────────────────

    private function mailer(
        Transport $transport,
        ?CachePort $cache,
        QueuePort $queue,
        int $maxInline = 3,
    ): Mailer {
        return new Mailer(
            transport: $transport,
            mime: new MimeBuilder(),
            queue: $queue,
            fromEmail: 'no-reply@test.local',
            fromName: 'Test',
            queueName: 'mail',
            cache: $cache,
            urgentViews: ['user::emails/verify', 'auth::emails/*'],
            urgentMaxInline: $maxInline,
            urgentLockTtl: 20,
        );
    }

    private function queue(): QueuePort
    {
        return new class implements QueuePort {
            /** @var list<array{0:string,1:string}> */
            private array $pushed = [];

            public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
            {
                $this->pushed[] = [$jobClass, $queue];

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
            public function fail(JobPayload $payload, ?\Throwable $e = null): void {}
        };
    }

    private function cache(): CachePort
    {
        return new class ($this->held) implements CachePort {
            public function __construct(private ArrayObject $held) {}

            public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
            {
                return new class ($name, $this->held) implements Lock {
                    public function __construct(private string $name, private ArrayObject $held) {}

                    public function acquire(): bool
                    {
                        if (isset($this->held[$this->name])) {
                            return false;
                        }
                        $this->held[$this->name] = true;

                        return true;
                    }

                    public function block(int $seconds, ?callable $callback = null): mixed { return $this->acquire(); }
                    public function release(): bool { unset($this->held[$this->name]); return true; }
                    public function owner(): string { return 'test'; }
                    public function forceRelease(): void { unset($this->held[$this->name]); }
                };
            }

            public function restoreLock(string $name, string $owner): Lock { return $this->lock($name); }
            public function get(string $key): mixed { return null; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function has(string $key): bool { return false; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function increment(string $key, int $by = 1): int { return $by; }
            public function deletePattern(string $pattern): int { return 0; }
            public function flush(): bool { return true; }
        };
    }
}
