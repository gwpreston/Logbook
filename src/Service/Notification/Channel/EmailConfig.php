<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Support\Config\Env;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * SMTP settings (MAIL_*; spec.md §9). Email is configured when MAIL_HOST is set.
 */
final readonly class EmailConfig
{
    public function __construct(
        public ?string $host,
        public int $port,
        /** tls (STARTTLS, required) | ssl (implicit TLS) | none */
        public string $encryption,
        public ?string $username,
        public ?string $password,
        /** "logbook@example.com" or "Logbook <logbook@example.com>" */
        public string $from,
        /** Default recipient; each owner may set their own. */
        public ?string $to,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        $encryption = strtolower($env->string('MAIL_ENCRYPTION', 'tls'));
        $port = filter_var($env->string('MAIL_PORT'), FILTER_VALIDATE_INT);

        return new self(
            host: $env->nullableString('MAIL_HOST'),
            port: is_int($port) && $port > 0 && $port < 65536 ? $port : 587,
            encryption: in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
            username: $env->nullableString('MAIL_USERNAME'),
            password: $env->nullableString('MAIL_PASSWORD'),
            from: $env->string('MAIL_FROM', 'logbook@localhost'),
            to: $env->nullableString('MAIL_TO'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== null;
    }

    /**
     * The SMTP transport (it connects only when a message is sent).
     */
    public function createTransport(?LoggerInterface $logger = null): TransportInterface
    {
        if ($this->host === null) {
            return new NullTransport();
        }

        $transport = new EsmtpTransport($this->host, $this->port, $this->encryption === 'ssl', null, $logger);
        if ($this->encryption === 'none') {
            $transport->setAutoTls(false);
        } elseif ($this->encryption === 'tls') {
            $transport->setRequireTls(true);
        }
        if ($this->username !== null) {
            $transport->setUsername($this->username);
            $transport->setPassword($this->password ?? '');
        }

        return $transport;
    }
}
