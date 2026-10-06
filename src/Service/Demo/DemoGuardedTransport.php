<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The app's one mail transport, with the demo's guarantee in front of it:
 * in an active demo no mail is sent (spec.md §7.36), whoever asks.
 */
final readonly class DemoGuardedTransport implements TransportInterface
{
    public function __construct(
        private TransportInterface $inner,
        private DemoMode $mode,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->mode->blocks(DemoRestriction::Outbound)) {
            throw new TransportException('Mail is switched off in the demo.');
        }

        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
