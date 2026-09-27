<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use RuntimeException;

/**
 * No such maintenance schedule on this vehicle.
 */
final class MaintenanceScheduleNotFound extends RuntimeException
{
}
