<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use RuntimeException;

/**
 * No such fill-up on this vehicle.
 */
final class FuelEntryNotFound extends RuntimeException
{
}
