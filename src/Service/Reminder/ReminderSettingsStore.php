<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use DateTimeZone;
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
    private const string EMAIL_RESULT = 'notifications.email_result';

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

    /**
     * Store a new user's starting delivery choices (the digest on, §7.11).
     */
    public function startNewUser(int $userId): void
    {
        $this->saveNotificationPreferences($userId, NotificationPreferences::forNewUser());
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

    /**
     * Email's last result (spec.md §6, Phase 36.2): `status` (`ok` |
     * `failed`), `at` (UTC, ATOM) and `error`, or null before the first send.
     *
     * @return array{status: string, at: string, error: ?string}|null
     */
    public function emailResult(int $userId): ?array
    {
        $value = $this->settings->find(self::EMAIL_RESULT, SettingScope::User, $userId)?->value;
        if (!is_array($value) || !is_string($value['status'] ?? null) || !is_string($value['at'] ?? null)) {
            return null;
        }

        return [
            'status' => $value['status'],
            'at' => $value['at'],
            'error' => is_string($value['error'] ?? null) ? $value['error'] : null,
        ];
    }

    public function recordEmailResult(int $userId, bool $delivered, ?string $error, DateTimeImmutable $at): void
    {
        $this->settings->save(self::EMAIL_RESULT, [
            'status' => $delivered ? 'ok' : 'failed',
            'at' => $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'error' => $delivered || $error === null ? null : mb_substr($error, 0, 255),
        ], SettingScope::User, $userId);
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
