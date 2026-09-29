<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

/**
 * Where a point of a vehicle's value series comes from (spec.md §7.1).
 */
enum ValuePointKind: string
{
    case Bought = 'bought';
    case Valuation = 'valuation';
    case Sold = 'sold';

    /**
     * Order on one day: the purchase first, the sale last.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Bought => -1,
            self::Valuation => 0,
            self::Sold => 1,
        };
    }
}
