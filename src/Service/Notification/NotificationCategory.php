<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Domain\Reminder\ReminderStatus;

/**
 * What a channel can be set to receive (spec.md §7.11 *What each channel
 * receives*, Phase 36.4). A test and the switched-off notice are in none:
 * they go to every usable channel.
 */
enum NotificationCategory: string
{
    case Due = 'due';
    case Overdue = 'overdue';
    case Digest = 'digest';
    case PriceAlerts = 'price_alerts';
    /** Admins only. */
    case JobFailures = 'job_failures';

    /**
     * The category of a whole notification, or null for one in none (a test,
     * the switched-off notice) and for reminders, which are classed one by
     * one (forReminder()).
     */
    public static function forKind(NotificationKind $kind): ?self
    {
        return match ($kind) {
            NotificationKind::Digest => self::Digest,
            NotificationKind::PriceAlert => self::PriceAlerts,
            NotificationKind::JobFailed => self::JobFailures,
            NotificationKind::Reminders, NotificationKind::Test, NotificationKind::ChannelOff => null,
        };
    }

    public static function forReminder(ReminderStatus $status): self
    {
        return $status === ReminderStatus::Overdue ? self::Overdue : self::Due;
    }

    /**
     * The categories offered on a card, in order.
     *
     * @return list<self>
     */
    public static function offered(bool $isAdmin): array
    {
        return $isAdmin ? self::cases() : [self::Due, self::Overdue, self::Digest, self::PriceAlerts];
    }

    public function labelKey(): string
    {
        return 'notifications.receives.' . $this->value;
    }
}
