<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

use DateTimeImmutable;

/**
 * A manual reminder as entered and validated: due on a date, at an odometer
 * reading, or both, whichever comes first (spec.md §7.6, Phase 26.4).
 */
final readonly class ManualReminderData
{
    public function __construct(
        public int $vehicleId,
        public string $title,
        /** Calendar date (midnight UTC), or null when due at an odometer only. */
        public ?DateTimeImmutable $dueOn,
        public int $leadTimeDays,
        public ?string $notes = null,
        /** The odometer it is due at, km (canonical decimal). */
        public ?string $dueKm = null,
    ) {
    }
}
