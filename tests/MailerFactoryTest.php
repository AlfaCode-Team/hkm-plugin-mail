<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Application\MailerFactory;
use Plugins\Mail\Infrastructure\Security\DkimSigner;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;
use Plugins\Mail\Infrastructure\Transport\Transport;
use Plugins\Mail\Infrastructure\Transport\TransportFactory;

/**
 * MailerFactory::forSmtp() builds a mailer for SMTP settings the plugin's own
 * config knows nothing about (a tenant's own server). It must never dial a
 * real socket in a test, so every case here substitutes a fake TransportFactory
 * that hands back an in-memory ArrayTransport instead of a real SmtpTransport
 * (TransportFactory is deliberately not `final` for exactly this reason).
 */
#[CoversClass(MailerFactory::class)]
final class MailerFactoryTest extends TestCase
{
    public function test_returns_a_mailer_contract(): void
    {
        $factory = $this->factory();

        $mailer = $factory->forSmtp($this->settings());

        self::assertInstanceOf(MailerContract::class, $mailer);
        self::assertInstanceOf(Mailer::class, $mailer);
    }

    public function test_forwards_settings_and_the_configured_smtp_array_to_the_transport_factory(): void
    {
        $transports = new SpyTransportFactory();
        $config     = ['smtp' => ['auth_mode' => 'login', 'timeout' => 5]];
        $factory    = new MailerFactory($transports, $config);
        $settings   = $this->settings();

        $factory->forSmtp($settings);

        self::assertSame($settings, $transports->lastSettings);
        self::assertSame(['auth_mode' => 'login', 'timeout' => 5], $transports->lastSmtpConfig);
    }

    public function test_message_defaults_to_the_settings_from_when_supplied(): void
    {
        $factory = $this->factory(['from' => ['address' => 'platform@example.test', 'name' => 'Platform']]);

        $mailer = $factory->forSmtp($this->settings(fromEmail: 'billing@tenant.test', fromName: 'Tenant Billing'));
        $from   = $mailer->message()->getFrom();

        self::assertNotNull($from);
        self::assertSame('billing@tenant.test', $from->email);
        self::assertSame('Tenant Billing', $from->name);
    }

    public function test_message_falls_back_to_the_configured_default_from_when_settings_omit_it(): void
    {
        $factory = $this->factory(['from' => ['address' => 'platform@example.test', 'name' => 'Platform']]);

        $mailer = $factory->forSmtp($this->settings(fromEmail: '', fromName: ''));
        $from   = $mailer->message()->getFrom();

        self::assertNotNull($from);
        self::assertSame('platform@example.test', $from->email);
        self::assertSame('Platform', $from->name);
    }

    public function test_dispatch_sends_immediately_through_the_fake_transport(): void
    {
        $transport = new ArrayTransport();
        $factory   = $this->factory(transport: $transport);
        $mailer    = $factory->forSmtp($this->settings());

        $mailer->dispatch($mailer->message()->to('customer@example.test')->subject('Hi')->text('Hello'));

        self::assertCount(1, $transport->messages());
    }

    public function test_enqueue_on_a_factory_mailer_delivers_inline_and_never_touches_a_queue(): void
    {
        $transport = new ArrayTransport();
        $factory   = $this->factory(transport: $transport);
        $mailer    = $factory->forSmtp($this->settings());

        $jobId = $mailer->enqueue(
            $mailer->message()->to('customer@example.test')->subject('Receipt')->text('Thanks'),
        );

        // '' is Mailer::enqueue()'s signal for "delivered, not queued" — there is
        // no QueuePort anywhere in this object graph for it to have reached.
        self::assertSame('', $jobId);
        self::assertCount(1, $transport->messages());
    }

    public function test_preview_never_sends(): void
    {
        $transport = new ArrayTransport();
        $factory   = $this->factory(transport: $transport);
        $mailer    = $factory->forSmtp($this->settings());

        $mime = $mailer->preview($mailer->message()->to('customer@example.test')->subject('Hi')->text('Hello'));

        self::assertStringContainsString('Subject: Hi', $mime);
        self::assertCount(0, $transport->messages());
    }

    public function test_dkim_is_applied_when_configured(): void
    {
        [$domain, $selector, $key] = $this->dkimFixture();
        $transport = new ArrayTransport();
        $factory   = $this->factory(['dkim' => ['domain' => $domain, 'selector' => $selector, 'private_key' => $key]], $transport);
        $mailer    = $factory->forSmtp($this->settings());

        $mailer->dispatch($mailer->message()->to('customer@example.test')->subject('Hi')->text('Hello'));

        self::assertStringContainsString('DKIM-Signature:', $transport->last()['mime']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $config */
    private function factory(array $config = [], ?Transport $transport = null): MailerFactory
    {
        return new MailerFactory(new StubTransportFactory($transport ?? new ArrayTransport()), $config);
    }

    private function settings(string $fromEmail = 'billing@tenant.test', string $fromName = 'Tenant Billing'): SmtpSettings
    {
        return new SmtpSettings(
            hosts: ['smtp.tenant.test'],
            port: 2525,
            username: 'tenant-user',
            password: 'super-secret',
            fromEmail: $fromEmail,
            fromName: $fromName,
        );
    }

    /** @return array{0: string, 1: string, 2: string} domain, selector, PEM key */
    private function dkimFixture(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return ['tenant.test', 'mail', $pem];
    }
}

/** Always hands back the SAME fake Transport instead of dialling real SMTP. */
final class StubTransportFactory extends TransportFactory
{
    public function __construct(private readonly Transport $transport)
    {
    }

    public function smtpFromSettings(SmtpSettings $settings, array $smtpConfig): Transport
    {
        return $this->transport;
    }
}

/** Records what MailerFactory hands it, still returning a fake Transport. */
final class SpyTransportFactory extends TransportFactory
{
    public ?SmtpSettings $lastSettings = null;

    /** @var array<string,mixed>|null */
    public ?array $lastSmtpConfig = null;

    public function smtpFromSettings(SmtpSettings $settings, array $smtpConfig): Transport
    {
        $this->lastSettings   = $settings;
        $this->lastSmtpConfig = $smtpConfig;

        return new ArrayTransport();
    }
}
