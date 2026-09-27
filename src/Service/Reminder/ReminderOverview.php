<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * An owner's reminders, grouped for the in-app list (and, from Phase 5, the
 * dashboard widget).
 */
final readonly class ReminderOverview
{
    /**
     * @param list<ReminderEntry> $open most urgent first, then by due date
     * @param list<ReminderEntry> $closed dismissed and done, most recently closed first
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function __construct(
        public array $open,
        public array $closed,
        public DateTimeImmutable $today,
    ) {
    }

    /**
     * @return list<ReminderEntry>
     */
    public function withStatus(ReminderStatus $status): array
    {
        return array_values(array_filter($this->open, static fn (ReminderEntry $e): bool => $e->reminder->status === $status));
    }

    /**
     * Due and overdue together: what needs attention now.
     */
    public function attentionCount(): int
    {
        return count(array_filter($this->open, static fn (ReminderEntry $e): bool => $e->reminder->status->isNotifiable()));
    }

    /**
     * The first $limit open reminders (the dashboard widget).
     *
     * @return list<ReminderEntry>
     */
    public function top(int $limit): array
    {
        return array_slice($this->open, 0, $limit);
    }
}
