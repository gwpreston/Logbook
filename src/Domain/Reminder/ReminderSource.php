<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

/**
 * Where a reminder comes from (spec.md §7.6).
 */
enum ReminderSource: string
{
    /** A maintenance schedule's next-due point. */
    case Schedule = 'schedule';
    /** A compliance document's expiry. */
    case Compliance = 'compliance';
    /** Added by hand. */
    case Manual = 'manual';

    /**
     * Generated reminders mirror their source and are kept in sync with it.
     */
    public function isGenerated(): bool
    {
        return $this !== self::Manual;
    }
}
