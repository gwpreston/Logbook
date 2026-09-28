<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

use Logbook\Domain\Feature\Feature;

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

    /**
     * The module the source belongs to: while it is switched off its
     * reminders are neither listed nor sent (spec.md §7.10).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Schedule => Feature::Maintenance,
            self::Compliance => Feature::Compliance,
            self::Manual => null,
        };
    }
}
