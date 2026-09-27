<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

/**
 * Where a reminder stands (spec.md §7.6). Upcoming, due and overdue follow
 * from the date and the lead time; dismissed and done are the owner's
 * choice and stick until the source moves to a new due point.
 */
enum ReminderStatus: string
{
    case Upcoming = 'upcoming';
    /** Within the lead time. */
    case Due = 'due';
    case Overdue = 'overdue';
    case Dismissed = 'dismissed';
    case Done = 'done';

    public function isOpen(): bool
    {
        return !$this->isClosed();
    }

    public function isClosed(): bool
    {
        return $this === self::Dismissed || $this === self::Done;
    }

    /**
     * Statuses that are sent out (once each; see ReminderNotifier).
     */
    public function isNotifiable(): bool
    {
        return $this === self::Due || $this === self::Overdue;
    }

    /**
     * Sort weight: most urgent first.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::Due => 1,
            self::Upcoming => 2,
            self::Dismissed, self::Done => 3,
        };
    }

    /**
     * Modifier of the .pill status colours.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Overdue => 'overdue',
            self::Due => 'soon',
            self::Done => 'valid',
            self::Upcoming, self::Dismissed => '',
        };
    }
}
