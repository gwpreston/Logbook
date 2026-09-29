<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use RuntimeException;

/**
 * A tyre or tyre set that is not one of the vehicle's.
 */
final class TyreNotFound extends RuntimeException
{
}
