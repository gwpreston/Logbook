<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Mail;

use Logbook\Service\Mail\EmailServerForm;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\SmtpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Settings → Delivery's validation (spec.md §7.11): a host only, ports,
 * addresses, a name of at most 100 characters, no line breaks anywhere
 * (header injection), the encryption values, the default port by
 * encryption, and the warning for a password sent unencrypted.
 */
final class EmailServerFormTest extends TestCase
{
    private const array VALID = [
        'host' => 'smtp.example.com',
        'port' => '',
        'encryption' => 'tls',
        'username' => 'me@example.com',
        'password' => 'app-password',
        'from_address' => 'logbook@example.com',
        'from_name' => '',
        'admin_recipient' => '',
    ];

    public function testAValidFormBecomesAServer(): void
    {
        $form = EmailServerForm::parse(['host' => ' SMTP.Example.com '] + self::VALID);

        self::assertSame([], $form->errors);
        self::assertNotNull($form->server);
        self::assertSame('smtp.example.com', $form->server->host);
        self::assertSame(587, $form->server->port);
        self::assertSame(MailEncryption::Tls, $form->server->encryption);
        self::assertSame('Logbook', $form->server->fromName, 'the default name');
        self::assertNull($form->server->adminRecipient);
        self::assertSame('app-password', $form->password);
        self::assertArrayNotHasKey('password', $form->values, 'the password is never put back');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function defaultPorts(): iterable
    {
        yield 'STARTTLS' => ['tls', 587];
        yield 'TLS' => ['ssl', 465];
        yield 'none' => ['none', 25];
    }

    #[DataProvider('defaultPorts')]
    public function testAnEmptyPortIsTheUsualOneForTheEncryption(string $encryption, int $port): void
    {
        self::assertSame($port, EmailServerForm::parse(['encryption' => $encryption] + self::VALID)->server?->port);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no host' => [['host' => ''], 'host', 'delivery.email.error.host_required'];
        yield 'a scheme' => [['host' => 'smtp://smtp.example.com'], 'host', 'delivery.email.error.host'];
        yield 'a path' => [['host' => 'smtp.example.com/relay'], 'host', 'delivery.email.error.host'];
        yield 'a port in the host' => [['host' => 'smtp.example.com:587'], 'host', 'delivery.email.error.host'];
        yield 'a space' => [['host' => 'smtp example.com'], 'host', 'delivery.email.error.host'];
        yield 'port 0' => [['port' => '0'], 'port', 'delivery.email.error.port'];
        yield 'port too high' => [['port' => '65536'], 'port', 'delivery.email.error.port'];
        yield 'port not a number' => [['port' => '58a'], 'port', 'delivery.email.error.port'];
        yield 'unknown encryption' => [['encryption' => 'starttls'], 'encryption', 'delivery.email.error.encryption'];
        yield 'no From address' => [['from_address' => ''], 'from_address', 'delivery.email.error.from_required'];
        yield 'a bad From address' => [['from_address' => 'logbook'], 'from_address', 'delivery.email.error.address'];
        yield 'a bad recipient' => [['admin_recipient' => 'me@'], 'admin_recipient', 'delivery.email.error.address'];
        yield 'a long name' => [['from_name' => str_repeat('a', 101)], 'from_name', 'delivery.email.error.from_name'];
        yield 'a long username' => [['username' => str_repeat('a', 255)], 'username', 'delivery.email.error.username'];
        yield 'a long password' => [['password' => str_repeat('a', 1001)], 'password', 'delivery.email.error.password_length'];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('invalid')]
    public function testInvalidInputIsRefusedWithItsMessage(array $change, string $field, string $key): void
    {
        $form = EmailServerForm::parse($change + self::VALID);

        self::assertSame($key, $form->errors[$field] ?? null);
        self::assertNull($form->server);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fields(): iterable
    {
        foreach (['host', 'username', 'password', 'from_address', 'from_name', 'admin_recipient'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('fields')]
    public function testALineBreakIsRefusedInEveryField(string $field): void
    {
        foreach (["a\r\nBcc: x@example.com", "a\nb", "a\rb"] as $value) {
            $form = EmailServerForm::parse([$field => 'x' . $value] + self::VALID);
            self::assertSame('delivery.email.error.line_break', $form->errors[$field] ?? null, $field);
            self::assertNull($form->server);
        }
    }

    public function testIpAddressesAreHosts(): void
    {
        self::assertTrue(EmailServerForm::validHost('192.0.2.10'));
        self::assertTrue(EmailServerForm::validHost('2001:db8::1'));
        self::assertTrue(EmailServerForm::validHost('mailpit'));
        self::assertFalse(EmailServerForm::validHost('12345'));
        self::assertFalse(EmailServerForm::validHost('-bad.example.com'));
    }

    public function testNoEncryptionWithAUsernameWarns(): void
    {
        self::assertSame(
            ['delivery.email.warning.unencrypted'],
            EmailServerForm::parse(['encryption' => 'none'] + self::VALID)->warnings,
        );
        self::assertSame([], EmailServerForm::parse(['encryption' => 'none', 'username' => ''] + self::VALID)->warnings);
        self::assertSame([], EmailServerForm::parse(self::VALID)->warnings);
    }

    public function testRemoveAndAnEmptyPassword(): void
    {
        $form = EmailServerForm::parse(['password' => '', 'remove_password' => '1'] + self::VALID);

        self::assertNull($form->password);
        self::assertTrue($form->removePassword);
    }

    public function testTheFormShowsASavedServerAndRoundTripsThroughTheSetting(): void
    {
        $server = new SmtpServer(
            'smtp.example.com',
            2525,
            MailEncryption::None,
            null,
            'a@example.com',
            'Garage',
            'me@example.com',
            true,
        );
        $stored = $server->toStored(7, '2026-10-06T10:00:00+00:00');

        self::assertSame(7, $stored['updated_by']);
        self::assertEquals($server, SmtpServer::fromStored($stored));
        self::assertSame('2525', EmailServerForm::values($server)['port']);
        self::assertSame('Logbook', EmailServerForm::values(null)['from_name']);
        self::assertNull(SmtpServer::fromStored(null));
        self::assertNull(SmtpServer::fromStored(['host' => '']));
    }

    public function testChangedFieldsAreNamedNeverValued(): void
    {
        $before = new SmtpServer('smtp.example.com', 587, MailEncryption::Tls, 'u', 'a@example.com');
        $after = new SmtpServer('smtp.other.example', 587, MailEncryption::Tls, 'u', 'b@example.com');

        self::assertSame(['host', 'from_address'], $after->changedFrom($before));
        self::assertSame([], $after->changedFrom($after));
        self::assertContains('encryption', $after->changedFrom(null));
    }
}
