<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\DTOs\MailtrapStream;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Application\MailerFactory;
use Plugins\Mail\Infrastructure\Security\DkimSigner;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;
use Plugins\Mail\Infrastructure\Transport\MessageTransport;
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

    // ── forMailtrap ──────────────────────────────────────────────────────────

    public function test_for_mailtrap_builds_a_mailer_on_the_api_transport(): void
    {
        $transport = new \Tests\Unit\Plugins\Mail\Fakes\RecordingMessageTransport();
        $factory   = new MailerFactory(new StubMailtrapTransportFactory($transport), []);

        $mailer = $factory->forMailtrap($this->mailtrapSettings());
        $mailer->dispatch($mailer->message()->to('customer@example.test')->subject('Hi')->text('Hello'));

        self::assertInstanceOf(MailerContract::class, $mailer);
        self::assertCount(1, $transport->messages);
    }

    public function test_for_mailtrap_prefers_the_settings_from_then_the_configured_one(): void
    {
        $transport = new \Tests\Unit\Plugins\Mail\Fakes\RecordingMessageTransport();
        $factory   = new MailerFactory(
            new StubMailtrapTransportFactory($transport),
            ['from' => ['address' => 'platform@example.test', 'name' => 'Platform']],
        );

        self::assertSame(
            'billing@tenant.test',
            $factory->forMailtrap($this->mailtrapSettings('billing@tenant.test'))->message()->getFrom()->email,
        );
        self::assertSame(
            'platform@example.test',
            $factory->forMailtrap($this->mailtrapSettings(''))->message()->getFrom()->email,
        );
    }

    public function test_for_mailtrap_never_signs_with_the_configured_dkim_key(): void
    {
        [$domain, $selector, $key] = $this->dkimFixture();
        $transport = new \Tests\Unit\Plugins\Mail\Fakes\RecordingMessageTransport();
        $factory   = new MailerFactory(
            new StubMailtrapTransportFactory($transport),
            ['dkim' => ['domain' => $domain, 'selector' => $selector, 'private_key' => $key]],
        );

        $mailer  = $factory->forMailtrap($this->mailtrapSettings());
        $message = $mailer->message()->to('customer@example.test')->subject('Hi')->text('Hello');
        $mailer->dispatch($message);

        // Mailtrap builds the MIME and signs it with the sending domain's keys;
        // preview() is the only MIME this mailer ever produces and it must not
        // claim a signature that is never sent.
        self::assertStringNotContainsString('DKIM-Signature:', $mailer->preview($message));
    }

    public function test_a_mailtrap_transport_with_no_http_client_bound_says_how_to_get_one(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('http.client');

        (new TransportFactory())->mailtrapFromSettings($this->mailtrapSettings());
    }

    public function test_the_configured_transport_name_selects_the_mailtrap_api(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('HttpClientPort');

        (new TransportFactory())->fromConfig([
            'transport' => 'mailtrap',
            'mailtrap'  => ['token' => 'tok', 'stream' => 'transactional'],
        ]);
    }

    // ── transport selection ──────────────────────────────────────────────────

    /**
     * An unrecognised MAIL_TRANSPORT used to fall through to SMTP. That is the
     * worst available outcome for a typo: the mailer builds fine, points at
     * whatever MAIL_HOST defaulted to, and the mistake surfaces — if at all —
     * as a connection error naming a host nobody configured.
     */
    public function test_an_unknown_transport_name_is_refused_rather_than_falling_back_to_smtp(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage("Unknown MAIL_TRANSPORT 'mailtrp'");

        (new TransportFactory())->fromConfig(['transport' => 'mailtrp']);
    }

    public function test_an_absent_or_blank_transport_still_means_smtp(): void
    {
        $factory = new TransportFactory();

        // The documented default — changing this would break every deployment
        // that never set the variable.
        self::assertInstanceOf(Transport::class, $factory->fromConfig([]));
        self::assertInstanceOf(Transport::class, $factory->fromConfig(['transport' => '']));
        self::assertInstanceOf(Transport::class, $factory->fromConfig(['transport' => '  SMTP  ']));
    }

    public function test_the_non_sending_transports_are_selectable_by_name(): void
    {
        $factory = new TransportFactory();

        self::assertInstanceOf(ArrayTransport::class, $factory->fromConfig(['transport' => 'array']));
        self::assertInstanceOf(Transport::class, $factory->fromConfig(['transport' => 'log']));
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

    private function mailtrapSettings(string $fromEmail = 'billing@tenant.test'): MailtrapSettings
    {
        return new MailtrapSettings('tenant-token', MailtrapStream::Transactional, fromEmail: $fromEmail);
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

/** Hands back a fake MESSAGE transport instead of dialling the Mailtrap API. */
final class StubMailtrapTransportFactory extends TransportFactory
{
    public function __construct(private readonly MessageTransport $transport)
    {
    }

    public function mailtrapFromSettings(MailtrapSettings $settings): MessageTransport
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
