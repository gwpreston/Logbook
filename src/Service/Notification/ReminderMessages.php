<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * One run's reminders for one person, written once per group of channels
 * (spec.md §7.11 *Reminders by category*, Phase 36.4): all of them for a
 * channel taking due and overdue, the due ones only, or the overdue ones
 * only. A channel taking both gets exactly what it got before.
 */
final readonly class ReminderMessages
{
    public function __construct(
        /** Every reminder of the run. */
        public Notification $all,
        /** The due ones only; null when none is due. */
        public ?Notification $due,
        /** The overdue ones only; null when none is overdue. */
        public ?Notification $overdue,
    ) {
    }

    /**
     * The message for a channel taking these categories, and the categories
     * it carries; null when it takes none of the run's reminders.
     *
     * @return array{Notification, non-empty-list<NotificationCategory>}|null
     */
    public function for(ChannelCategories $categories): ?array
    {
        $due = $this->due !== null && $categories->takes(NotificationCategory::Due);
        $overdue = $this->overdue !== null && $categories->takes(NotificationCategory::Overdue);

        return match (true) {
            $due && $overdue => [$this->all, [NotificationCategory::Due, NotificationCategory::Overdue]],
            $due => [$this->overdue === null ? $this->all : $this->due, [NotificationCategory::Due]],
            $overdue => [$this->due === null ? $this->all : $this->overdue, [NotificationCategory::Overdue]],
            default => null,
        };
    }
}
