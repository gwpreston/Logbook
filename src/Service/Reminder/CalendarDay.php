<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * One day of a reminders calendar (spec.md §7.6 *Calendar view*). Days of
 * the neighbouring months fill the first and last week and carry no items.
 */
final readonly class CalendarDay
{
    /** A day shows this many items; the rest become *+N more*. */
    public const int SHOWN = 3;

    /**
     * @param list<ReminderEntry> $items open ones first, most urgent first, then closed ones
     */
    public function __construct(
        public DateTimeImmutable $date,
        public bool $inMonth,
        public bool $isToday,
        public array $items = [],
    ) {
    }

    /** `YYYY-MM-DD`, for `?day=` and the anchor. */
    public function key(): string
    {
        return $this->date->format('Y-m-d');
    }

    /** The anchor id: `day-YYYY-MM-DD`. */
    public function anchor(): string
    {
        return 'day-' . $this->key();
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    /**
     * @return list<ReminderEntry>
     */
    public function shown(): array
    {
        return array_slice($this->items, 0, self::SHOWN);
    }

    /** How many more than SHOWN: the *+N more* link. */
    public function more(): int
    {
        return max(0, count($this->items) - self::SHOWN);
    }

    /**
     * @return list<ReminderEntry>
     */
    public function open(): array
    {
        return array_values(array_filter($this->items, static fn (ReminderEntry $e): bool => $e->reminder->status->isOpen()));
    }

    public function count(ReminderStatus $status): int
    {
        return count(array_filter($this->items, static fn (ReminderEntry $e): bool => $e->reminder->status === $status));
    }

    /**
     * The most urgent open status (overdue before due before upcoming),
     * null with no open item: the widget's mark.
     */
    public function urgent(): ?ReminderStatus
    {
        // Open items come first, most urgent first (CalendarMonth::of).
        $first = $this->items[0] ?? null;

        return $first !== null && $first->reminder->status->isOpen() ? $first->reminder->status : null;
    }
}
