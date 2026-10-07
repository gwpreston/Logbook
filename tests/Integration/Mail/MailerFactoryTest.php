<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mail;

use Logbook\Service\Demo\DemoGuardedTransport;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\MailerFactory;
use Logbook\Service\Mail\SmtpServer;
use Logbook\Tests\Support\AppTestCase;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * MailerFactory (spec.md §7.11 *One transport*): each encryption maps to
 * the right SMTP connection, with the 10-second timeout, the credentials
 * only with a username, and demo mode's guard in front.
 */
final class MailerFactoryTest extends AppTestCase
{
    public function testEachEncryption(): void
    {
        $tls = $this->inner(MailEncryption::Tls, 587);
        self::assertSame('smtp://smtp.example.com:587', (string) $tls);
        self::assertTrue(self::property($tls, 'requireTls'));
        self::assertTrue(self::property($tls, 'autoTls'));

        $ssl = $this->inner(MailEncryption::Ssl, 465);
        self::assertSame('smtps://smtp.example.com', (string) $ssl);
        self::assertFalse(self::property($ssl, 'requireTls'));

        $none = $this->inner(MailEncryption::None, 25);
        self::assertSame('smtp://smtp.example.com', (string) $none, 'port 25 is the default');
        self::assertFalse(self::property($none, 'autoTls'));
        self::assertFalse(self::property($none, 'requireTls'));

        $stream = $tls->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(MailerFactory::TIMEOUT_SECONDS, $stream->getTimeout());
    }

    public function testCredentialsOnlyWithAUsername(): void
    {
        $with = $this->inner(MailEncryption::Tls, 587, 'me', 'secret-pass');
        self::assertSame('me', $with->getUsername());
        self::assertSame('secret-pass', $with->getPassword());

        $without = $this->inner(MailEncryption::Tls, 587, null, 'secret-pass');
        self::assertSame('', $without->getUsername());
        self::assertSame('', $without->getPassword());
    }

    public function testAnIpv6HostIsBracketed(): void
    {
        self::assertSame('smtp://[2001:db8::1]:587', (string) $this->inner(MailEncryption::Tls, 587, host: '2001:db8::1'));
    }

    private function inner(
        MailEncryption $encryption,
        int $port,
        ?string $username = null,
        ?string $password = null,
        string $host = 'smtp.example.com',
    ): EsmtpTransport {
        $server = new SmtpServer($host, $port, $encryption, $username, 'logbook@example.com');
        $built = $this->service($this->createApp(), MailerFactory::class)->build($server, $password);
        self::assertInstanceOf(DemoGuardedTransport::class, $built);
        $inner = (new ReflectionProperty(DemoGuardedTransport::class, 'inner'))->getValue($built);
        self::assertInstanceOf(EsmtpTransport::class, $inner);

        return $inner;
    }

    private static function property(EsmtpTransport $transport, string $name): mixed
    {
        return (new ReflectionProperty(EsmtpTransport::class, $name))->getValue($transport);
    }
}
