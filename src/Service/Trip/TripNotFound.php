<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use RuntimeException;

/**
 * No such trip on this vehicle, or not one this user may see.
 */
final class TripNotFound extends RuntimeException
{
}
