<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * A recurring job (spec.md §6 MaintenanceSchedule). The last-done point and
 * the next-due point are computed by Service\Maintenance\ScheduleCalculator
 * and stored, so they can be queried (reminders, Phase 4).
 */
final readonly class MaintenanceSchedule
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public MaintenanceScheduleData $data,
        public DonePoint $lastDone,
        public NextDue $nextDue,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
