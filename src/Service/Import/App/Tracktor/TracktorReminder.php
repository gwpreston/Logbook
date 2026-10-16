<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * One of Tracktor's `reminders`.
 */
final readonly class TracktorReminder
{
    public const array TYPES = ['maintenance', 'insurance', 'pollution', 'registration', 'inspection', 'custom'];

    /** `remindSchedule` → days ahead (spec.md §7.13). */
    public const array LEAD_DAYS = [
        'same_day' => 0,
        'one_day_before' => 1,
        'three_days_before' => 3,
        'one_week_before' => 7,
        'one_month_before' => 30,
    ];

    public function __construct(
        public int $line,
        public string $id,
        public string $type,
        public ?string $dueDate,
        public string $schedule,
        /** `none`, `daily`, `weekly`, `monthly` or `yearly`. */
        public string $recurrence,
        public int $interval,
        public string $note,
        public bool $completed,
    ) {
    }

    public function leadDays(): int
    {
        return self::LEAD_DAYS[$this->schedule] ?? 0;
    }
}
