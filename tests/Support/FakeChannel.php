<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use RuntimeException;

/**
 * A notification channel for tests: records what it was asked to send and
 * delivers, fails or throws as told.
 */
final class FakeChannel implements NotificationChannel
{
    /** @var list<Notification> */
    public array $sent = [];

    /**
     * @param 'deliver'|'fail'|'throw' $behaviour
     */
    public function __construct(
        private readonly string $key,
        private readonly bool $configured = true,
        public string $behaviour = 'deliver',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $this->sent[] = $notification;

        return match ($this->behaviour) {
            'deliver' => DeliveryResult::delivered($this->key),
            'fail' => DeliveryResult::failed($this->key, 'refused'),
            'throw' => throw new RuntimeException('connection reset'),
        };
    }
}
