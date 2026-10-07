<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationCategory;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\ReceivesCategories;
use Logbook\Service\Notification\Recipient;

/**
 * A test channel that receives only some categories (Phase 36.4), and
 * records what it was sent.
 */
final class CategorisedChannel implements NotificationChannel, ReceivesCategories
{
    /** @var list<Notification> */
    public array $sent = [];

    /**
     * @param list<NotificationCategory> $categories
     * @param 'deliver'|'fail' $behaviour
     */
    public function __construct(
        private readonly string $key,
        private readonly array $categories,
        private readonly string $behaviour = 'deliver',
    ) {
    }

    public function categories(NotificationPreferences $preferences): ChannelCategories
    {
        return ChannelCategories::of($this->categories);
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
        return true;
    }

    public function reaches(Recipient $recipient): bool
    {
        return true;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $this->sent[] = $notification;

        return $this->behaviour === 'deliver'
            ? DeliveryResult::delivered($this->key)
            : DeliveryResult::failed($this->key, 'refused');
    }
}
