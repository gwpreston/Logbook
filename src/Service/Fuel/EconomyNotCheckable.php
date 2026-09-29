<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use RuntimeException;

/**
 * The fill-up closes no checkable segment, so there is no economy to confirm.
 */
final class EconomyNotCheckable extends RuntimeException
{
}
