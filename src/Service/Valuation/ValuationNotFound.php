<?php

declare(strict_types=1);

namespace Logbook\Service\Valuation;

use RuntimeException;

/**
 * No such valuation on this vehicle.
 */
final class ValuationNotFound extends RuntimeException
{
}
