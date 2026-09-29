<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Support\Number\Decimal;

/**
 * The shares of distance and volume that fell in one calendar month
 * (spec.md §7.3, *Economy by month*), canonical decimals. Year 0 is the
 * average column (every year added up).
 */
final readonly class MonthlyEconomyRow
{
    /** Less distance than this in a month shows "—". */
    public const string MIN_DISTANCE_KM = '200';

    public function __construct(
        public int $year,
        /** 1–12. */
        public int $month,
        /** Litres (kWh). */
        public string $volume,
        /** Kilometres. */
        public string $distanceKm,
    ) {
    }

    /**
     * Enough driving in the month to show a figure (volume ÷ distance,
     * converted at the edge, never an average of mpg values).
     */
    public function hasFigure(): bool
    {
        return Decimal::compare($this->distanceKm, self::MIN_DISTANCE_KM) >= 0
            && Decimal::compare($this->volume, '0') > 0;
    }
}
