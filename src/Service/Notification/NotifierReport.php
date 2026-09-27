<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * What one ReminderNotifier run did for one owner.
 */
final readonly class NotifierReport
{
    public function __construct(
        /** Reminders delivered through at least one channel. */
        public int $remindersSent,
        public bool $digestSent,
    ) {
    }
}
