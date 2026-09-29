<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Vehicle\VehicleType;
use RuntimeException;

/**
 * A vehicle type change refused because a tyre is fitted at a position the
 * new type lacks (spec.md §7.1): "Remove the tyres first: a motorbike has no
 * rear left wheel."
 */
final class TyresBlockTypeChange extends RuntimeException
{
    public function __construct(public readonly VehicleType $type, public readonly TyrePosition $position)
    {
        parent::__construct(sprintf('A %s has no %s position.', $type->value, $position->value));
    }
}
