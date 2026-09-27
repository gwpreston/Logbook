<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands in for the SMTP transport: keeps every email instead of sending it.
 */
final class RecordingMailTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];
    public bool $failing = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->failing) {
            throw new TransportException('Connection to smtp.test refused.');
        }
        assert($message instanceof Email);
        $this->sent[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
