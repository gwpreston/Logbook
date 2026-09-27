<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceSchedule;

/**
 * A schedule with how urgent it is today.
 */
final readonly class ScheduleState
{
    public function __construct(
        public MaintenanceSchedule $schedule,
        public DueState $due,
    ) {
    }
}
