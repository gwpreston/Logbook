<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Reminder\ReminderEntry;

/**
 * A vehicle on the fleet summary widget: its current odometer and its most
 * urgent open reminder.
 */
final readonly class FleetVehicle
{
    public function __construct(
        public Vehicle $vehicle,
        public ?OdometerReading $latest,
        public ?ReminderEntry $next,
    ) {
    }
}
