<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Notification\NotificationPreferences;

/**
 * The owner's reminder and notification settings, kept as user-scoped rows
 * of the settings table (spec.md §6 Setting).
 */
final readonly class ReminderSettingsStore
{
    private const string REMINDERS = 'reminders';
    private const string NOTIFICATIONS = 'notifications';
    private const string DIGEST_MONTH = 'notifications.digest_month';
    private const string CALENDAR = 'calendar_feed';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function reminderPreferences(int $userId): ReminderPreferences
    {
        return ReminderPreferences::fromArray($this->settings->find(self::REMINDERS, SettingScope::User, $userId)?->value);
    }

    public function saveReminderPreferences(int $userId, ReminderPreferences $preferences): void
    {
        $this->settings->save(self::REMINDERS, $preferences->toArray(), SettingScope::User, $userId);
    }

    public function notificationPreferences(int $userId): NotificationPreferences
    {
        $stored = $this->settings->find(self::NOTIFICATIONS, SettingScope::User, $userId);

        return NotificationPreferences::fromArray($stored?->value);
    }

    public function saveNotificationPreferences(int $userId, NotificationPreferences $preferences): void
    {
        $this->settings->save(self::NOTIFICATIONS, $preferences->toArray(), SettingScope::User, $userId);
    }

    /**
     * The month ("2026-10", owner's time zone) the last digest was sent for.
     */
    public function digestMonth(int $userId): ?string
    {
        $value = $this->settings->find(self::DIGEST_MONTH, SettingScope::User, $userId)?->value;

        return is_string($value) ? $value : null;
    }

    public function markDigestSent(int $userId, string $month): void
    {
        $this->settings->save(self::DIGEST_MONTH, $month, SettingScope::User, $userId);
    }

    /**
     * Keyed hash of the calendar feed token; null when the feed is off.
     */
    public function calendarTokenHash(int $userId): ?string
    {
        $value = $this->settings->find(self::CALENDAR, SettingScope::User, $userId)?->value;

        return is_array($value) && is_string($value['token_hash'] ?? null) ? $value['token_hash'] : null;
    }

    public function setCalendarTokenHash(int $userId, ?string $hash): void
    {
        if ($hash === null) {
            $this->settings->delete(self::CALENDAR, SettingScope::User, $userId);

            return;
        }

        $this->settings->save(self::CALENDAR, ['token_hash' => $hash], SettingScope::User, $userId);
    }
}
