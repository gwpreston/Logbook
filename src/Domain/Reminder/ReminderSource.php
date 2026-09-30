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
    /**
     * A vehicle's tyres, worn or ageing (Phase 11.2). Its source id is the
     * vehicle's own id: one tyre reminder per vehicle, never per tyre.
     */
    case Tyre = 'tyre';
    /**
     * A vehicle's *First MOT due* date, until its first inspection document
     * (Phase 21.2). Its source id is the vehicle's own id, as for tyres.
     */
    case FirstInspection = 'first_inspection';
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
            self::Compliance, self::FirstInspection => Feature::Compliance,
            self::Tyre => Feature::Tyres,
            self::Manual => null,
        };
    }
}
