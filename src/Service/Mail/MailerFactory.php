<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use Logbook\Service\Demo\DemoGuardedTransport;
use Logbook\Service\Demo\DemoMode;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The only place a mail transport is built (spec.md §7.11 *One
 * transport*; an architecture test holds it to that): SMTP to the given
 * server, with demo mode's guard in front (§7.36). It connects only when a
 * message is sent, waits at most 10 seconds for the connection and for
 * each reply, and never retries.
 */
final readonly class MailerFactory implements TransportFactory
{
    public const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private DemoMode $demo,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function build(SmtpServer $server, #[SensitiveParameter] ?string $password): TransportInterface
    {
        // An IPv6 address goes in brackets, or "host:port" is ambiguous.
        $host = filter_var($server->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $server->host . ']'
            : $server->host;
        $transport = new EsmtpTransport(
            $host,
            $server->port,
            $server->encryption === MailEncryption::Ssl,
            null,
            $this->logger,
        );
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }
        if ($server->encryption === MailEncryption::None) {
            $transport->setAutoTls(false);
        } elseif ($server->encryption === MailEncryption::Tls) {
            $transport->setRequireTls(true);
        }
        if ($server->username !== null) {
            $transport->setUsername($server->username);
            $transport->setPassword($password ?? '');
        }

        return new DemoGuardedTransport($transport, $this->demo);
    }
}
