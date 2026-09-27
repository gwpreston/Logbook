<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use RuntimeException;

/**
 * No such reading on this vehicle.
 */
final class OdometerReadingNotFound extends RuntimeException
{
}
