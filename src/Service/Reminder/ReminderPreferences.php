<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Support\Number\Decimal;

/**
 * An owner's lead times (spec.md §7.6): how early something counts as due.
 * Stored as the `reminders` user setting.
 */
final readonly class ReminderPreferences
{
    public const int DEFAULT_SCHEDULE_DAYS = 30;
    public const string DEFAULT_SCHEDULE_KM = '1000.000';
    public const int DEFAULT_DOCUMENT_DAYS = 30;
    public const int DEFAULT_MANUAL_DAYS = 7;
    public const int MAX_DAYS = 365;
    public const string MAX_KM = '100000';

    public function __construct(
        /** Days before a schedule is due. */
        public int $scheduleDays = self::DEFAULT_SCHEDULE_DAYS,
        /** Kilometres before a schedule's distance limit (canonical decimal). */
        public string $scheduleKm = self::DEFAULT_SCHEDULE_KM,
        /** Days before a document expires. */
        public int $documentDays = self::DEFAULT_DOCUMENT_DAYS,
        /** Default lead time of a new manual reminder. */
        public int $manualDays = self::DEFAULT_MANUAL_DAYS,
    ) {
    }

    /**
     * From the stored setting; anything missing or out of range falls back
     * to the default, so a hand-edited row can never break the app.
     */
    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $km = $value['schedule_km'] ?? null;

        return new self(
            self::days($value['schedule_days'] ?? null, self::DEFAULT_SCHEDULE_DAYS),
            is_string($km) && Decimal::isCanonical($km)
                && Decimal::compare($km, '0') >= 0 && Decimal::compare($km, self::MAX_KM) <= 0
                ? Decimal::round($km, 3)
                : self::DEFAULT_SCHEDULE_KM,
            self::days($value['document_days'] ?? null, self::DEFAULT_DOCUMENT_DAYS),
            self::days($value['manual_days'] ?? null, self::DEFAULT_MANUAL_DAYS),
        );
    }

    /**
     * @return array{schedule_days: int, schedule_km: string, document_days: int, manual_days: int}
     */
    public function toArray(): array
    {
        return [
            'schedule_days' => $this->scheduleDays,
            'schedule_km' => $this->scheduleKm,
            'document_days' => $this->documentDays,
            'manual_days' => $this->manualDays,
        ];
    }

    private static function days(mixed $value, int $default): int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_DAYS ? $value : $default;
    }
}
