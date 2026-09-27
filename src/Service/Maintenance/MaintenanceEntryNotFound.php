<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use RuntimeException;

/**
 * No such maintenance entry on this vehicle.
 */
final class MaintenanceEntryNotFound extends RuntimeException
{
}
