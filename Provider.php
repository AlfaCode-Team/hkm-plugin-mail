<?php

declare(strict_types=1);

namespace Plugins\Mail;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\API\Contracts\MailerFactoryContract;
use Plugins\Mail\Application\Jobs\SendMailJob;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Application\MailerFactory;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\Transport;
use Plugins\Mail\Infrastructure\Transport\TransportFactory;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * Mail plugin — native, dependency-free mail delivery.
 *
 * Binds the Transport (from config), the MimeBuilder, an optional DkimSigner and
 * the Mailer — which satisfies BOTH the kernel `MailPort` (so any module's
 * view-based `send()`/`queue()` just works) and the richer `MailerContract`.
 *
 * Also publishes `MailerFactoryContract`: the seam a project uses to build a
 * mailer for SMTP settings this module's own config knows nothing about (a
 * tenant's own server, most commonly) without reaching into the internal
 * `Transport`/`SmtpTransport`/`MimeBuilder` bindings.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'mail.delivery';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [MailPort::class, MailerContract::class, MailerFactoryContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $config     = $this->config();
        $transports = new TransportFactory();

        $container->bindInternal(TransportFactory::class, static fn(): TransportFactory => $transports);

        $container->bindInternal(Transport::class, static fn(): Transport => $transports->fromConfig($config));

        $container->bindInternal(MimeBuilder::class, static fn(): MimeBuilder => new MimeBuilder());

        $container->bind(Mailer::class, function (ModuleContainer $c) use ($config, $transports): Mailer {
            return new Mailer(
                transport: $c->make(Transport::class),
                mime:      $c->make(MimeBuilder::class),
                dkim:      $transports->dkim($config),
                views:     $c->has(ViewRendererContract::class) ? $c->make(ViewRendererContract::class) : null,
                queue:     $c->has(QueuePort::class) ? $c->make(QueuePort::class) : null,
                fromEmail: (string) ($config['from']['address'] ?? ''),
                fromName:  (string) ($config['from']['name'] ?? ''),
                charset:   (string) ($config['charset'] ?? 'UTF-8'),
                queueName: (string) ($config['queue'] ?? 'mail'),
                // Bounds inline delivery of urgent mail; absent is fine, it just
                // means urgent mail queues like everything else.
                cache:           $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                urgentViews:     array_values((array) ($config['urgent']['views'] ?? [])),
                urgentMaxInline: (int) ($config['urgent']['max_inline'] ?? 0),
                urgentLockTtl:   (int) ($config['urgent']['lock_ttl'] ?? 20),
            );
        });

        // One instance satisfies MailPort, MailerContract and the concrete class.
        $container->bind(MailPort::class, static fn(ModuleContainer $c): Mailer => $c->make(Mailer::class));
        $container->bind(MailerContract::class, static fn(ModuleContainer $c): Mailer => $c->make(Mailer::class));

        // Publishes a way to build a mailer for SMTP settings THIS module's own
        // config knows nothing about (a tenant's own server, most commonly) —
        // without a project reaching into Transport/SmtpTransport/MimeBuilder,
        // which stay bindInternal. Delivery is always inline (see MailerFactory).
        $container->bind(MailerFactoryContract::class, function (ModuleContainer $c) use ($config, $transports): MailerFactoryContract {
            return new MailerFactory(
                transports: $transports,
                config:     $config,
                views:      $c->has(ViewRendererContract::class) ? $c->make(ViewRendererContract::class) : null,
            );
        });

        // Background delivery job resolves the same Transport. The logger is
        // optional and resolved the same way the Mailer's are -- a dead-lettered
        // mail is the one event in this plugin nothing else reports, so it has
        // to reach the application log when a LoggerPort is available.
        $container->bindInternal(SendMailJob::class, static fn(ModuleContainer $c): SendMailJob =>
            new SendMailJob(
                transport: $c->make(Transport::class),
                logger:    $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Job is declared in module.json; nothing to hook here.
    }

    /**
     * Mail configuration, from the compiled config manifest.
     *
     * The manifest deep-merges this plugin's config/mail.php with the project's,
     * so a project overriding one key (say mail.from.address) inherits every
     * other default instead of having to copy the whole file — which is what the
     * previous project-file-REPLACES-plugin-file lookup forced.
     *
     * Falls back to reading the shipped file directly when no manifest exists,
     * so the plugin still works in a unit test that never ran the BootPipeline.
     *
     * @return array<string,mixed>
     */
    private function config(): array
    {
        $config = \function_exists('config') ? config('mail') : null;

        if (\is_array($config) && $config !== []) {
            return $config;
        }

        /** @var array<string,mixed> $fallback */
        $fallback = require __DIR__ . '/config/mail.php';

        return \is_array($fallback) ? $fallback : [];
    }
}
