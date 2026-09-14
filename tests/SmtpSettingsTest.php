<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\Domain\MailException;

/**
 * SmtpSettings is a tenant-supplied credential bag — construction validates
 * what could otherwise fail confusingly deep inside SmtpTransport, and the
 * password must never surface anywhere this object is printed.
 */
#[CoversClass(SmtpSettings::class)]
final class SmtpSettingsTest extends TestCase
{
    public function test_valid_settings_normalise_hosts_and_trim_names(): void
    {
        $settings = new SmtpSettings(
            hosts: [' smtp1.tenant.test ', '', 'smtp2.tenant.test', '  '],
            port: 2525,
            encryption: 'ssl',
            username: 'tenant-user',
            password: 'super-secret',
            fromEmail: '  billing@tenant.test  ',
            fromName: '  Tenant Billing  ',
        );

        self::assertSame(['smtp1.tenant.test', 'smtp2.tenant.test'], $settings->hosts);
        self::assertSame(2525, $settings->port);
        self::assertSame('ssl', $settings->encryption);
        self::assertSame('tenant-user', $settings->username);
        self::assertSame('super-secret', $settings->password);
        self::assertSame('billing@tenant.test', $settings->fromEmail);
        self::assertSame('Tenant Billing', $settings->fromName);
    }

    public function test_defaults_are_port_587_tls_and_empty_credentials(): void
    {
        $settings = new SmtpSettings(hosts: ['smtp.tenant.test']);

        self::assertSame(587, $settings->port);
        self::assertSame('tls', $settings->encryption);
        self::assertSame('', $settings->username);
        self::assertSame('', $settings->password);
        self::assertSame('', $settings->fromEmail);
        self::assertSame('', $settings->fromName);
    }

    public function test_empty_host_list_is_rejected(): void
    {
        $this->expectException(MailException::class);
        new SmtpSettings(hosts: []);
    }

    public function test_a_host_list_of_only_blank_strings_is_rejected(): void
    {
        $this->expectException(MailException::class);
        new SmtpSettings(hosts: ['', '   ']);
    }

    public function test_port_zero_is_rejected(): void
    {
        $this->expectException(MailException::class);
        new SmtpSettings(hosts: ['smtp.tenant.test'], port: 0);
    }

    public function test_port_above_65535_is_rejected(): void
    {
        $this->expectException(MailException::class);
        new SmtpSettings(hosts: ['smtp.tenant.test'], port: 65536);
    }

    public function test_unknown_encryption_is_rejected(): void
    {
        $this->expectException(MailException::class);
        new SmtpSettings(hosts: ['smtp.tenant.test'], encryption: 'starttls');
    }

    public function test_none_and_ssl_encryption_are_accepted(): void
    {
        self::assertSame('none', (new SmtpSettings(hosts: ['h'], encryption: 'none'))->encryption);
        self::assertSame('ssl', (new SmtpSettings(hosts: ['h'], encryption: 'ssl'))->encryption);
    }

    public function test_validation_failure_messages_never_contain_the_password(): void
    {
        $secret = 'super-secret-password';

        foreach ([
            static fn () => new SmtpSettings(hosts: [], password: $secret),
            static fn () => new SmtpSettings(hosts: ['h'], port: 0, password: $secret),
            static fn () => new SmtpSettings(hosts: ['h'], encryption: 'bogus', password: $secret),
        ] as $build) {
            try {
                $build();
                self::fail('Expected a MailException.');
            } catch (MailException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function test_password_is_redacted_from_var_dump_and_print_r(): void
    {
        $secret   = 'super-secret-password';
        $settings = new SmtpSettings(hosts: ['h'], password: $secret);

        self::assertStringNotContainsString($secret, print_r($settings, true));

        ob_start();
        var_dump($settings);
        $dump = ob_get_clean();

        self::assertStringNotContainsString($secret, $dump);
    }

    public function test_password_is_never_in_tostring(): void
    {
        $secret   = 'super-secret-password';
        $settings = new SmtpSettings(hosts: ['h'], username: 'someone', password: $secret);

        self::assertStringNotContainsString($secret, (string) $settings);
        self::assertStringNotContainsString($secret, $settings->__toString());
    }
}
