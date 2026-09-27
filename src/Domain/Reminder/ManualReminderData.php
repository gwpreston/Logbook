<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

use DateTimeImmutable;

/**
 * A manual reminder as entered and validated.
 */
final readonly class ManualReminderData
{
    public function __construct(
        public int $vehicleId,
        public string $title,
        /** Calendar date (midnight UTC). */
        public DateTimeImmutable $dueOn,
        public int $leadTimeDays,
        public ?string $notes = null,
    ) {
    }
}
