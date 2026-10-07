<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Ai\Redactor;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use Throwable;

/**
 * One user's usable personal channel, as the dispatcher sees it: the
 * sender with that user's settings. Its error text is redacted of the
 * channel's secrets before it is stored, logged or shown.
 */
final readonly class BoundChannel implements NotificationChannel
{
    public function __construct(
        private PersonalSender $sender,
        private ChannelSettings $settings,
        private bool $restricted,
    ) {
    }

    public function key(): string
    {
        return $this->sender->definition()->key;
    }

    public function label(): string
    {
        return $this->sender->definition()->label;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function reaches(Recipient $recipient): bool
    {
        return true;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        try {
            $result = $this->sender->send($notification, $recipient, $this->settings, $this->restricted);
        } catch (Throwable $e) {
            // Caught here, not by the dispatcher, so the message is redacted before it is logged.
            $result = DeliveryResult::failed($this->key(), $e->getMessage());
        }

        if ($result->delivered || $result->error === null) {
            return $result;
        }
        $error = Redactor::redact($result->error, $this->settings->secretValues());

        return $result->refused
            ? DeliveryResult::refused($result->channel, $error)
            : DeliveryResult::failed($result->channel, $error);
    }
}
