<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use RuntimeException;

/**
 * No such vehicle, or it belongs to someone else (indistinguishable on purpose).
 */
final class VehicleNotFound extends RuntimeException
{
}
