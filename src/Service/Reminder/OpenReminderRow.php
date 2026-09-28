<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * The few columns of an open reminder that the due counts need (read by
 * ReminderRepository::listOpenForCounts()).
 */
final readonly class OpenReminderRow
{
    public function __construct(
        public int $vehicleId,
        public ReminderSource $source,
        public ReminderStatus $status,
        /** Calendar date, or null for a distance-only schedule. */
        public ?DateTimeImmutable $dueOn,
        public int $leadTimeDays,
    ) {
    }
}
