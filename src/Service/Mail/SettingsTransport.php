<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use Logbook\Service\Ai\Redactor;
use Logbook\Service\Ai\SecretUnreadable;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The app's mail transport (spec.md §7.11): on every send it reads the
 * saved server and password and has MailerFactory build the connection,
 * so a change in Settings → Delivery applies at once. Nothing is sent
 * while no server is saved, or while a saved password can't be opened.
 * The server's error text is redacted before anyone sees it (the original
 * exception is not chained: its message may hold the password).
 */
final readonly class SettingsTransport implements TransportInterface
{
    public function __construct(
        private MailConfig $config,
        private NotificationSecrets $secrets,
        private TransportFactory $factory,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $server = $this->config->effective();
        if ($server === null) {
            throw new TransportException('Email is not set up (Settings → Delivery).');
        }
        $password = null;
        if ($server->hasPassword && $server->username !== null) {
            try {
                $password = $this->secrets->open(null, NotificationSecrets::SMTP_PASSWORD);
            } catch (SecretUnreadable $e) {
                throw new TransportException($e->getMessage() . ' Enter the password again in Settings → Delivery.');
            }
            if ($password === null) {
                throw new TransportException('The email server\'s password is missing. Enter it again in Settings → Delivery.');
            }
        }

        try {
            return $this->factory->build($server, $password)->send($message, $envelope);
        } catch (TransportExceptionInterface $e) {
            throw new TransportException(Redactor::redact($e->getMessage(), $password === null ? [] : [$password]));
        }
    }

    public function __toString(): string
    {
        return 'settings://';
    }
}
