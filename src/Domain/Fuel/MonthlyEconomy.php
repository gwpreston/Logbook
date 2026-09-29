<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Support\Number\Decimal;

/**
 * Economy for each calendar month, per year and averaged across years
 * (spec.md §7.3, *Economy by month*), for one kind of energy.
 */
final readonly class MonthlyEconomy
{
    /** Year columns shown (the most recent). */
    public const int MAX_YEARS = 5;

    /**
     * @param list<MonthlyEconomyRow> $rows one per year and month with any share, any order
     */
    public function __construct(
        public EnergyKind $kind,
        public array $rows,
        /** The owner's current calendar year (the chart's line). */
        public int $currentYear,
    ) {
    }

    /**
     * The calendar years with data, oldest first, the last MAX_YEARS at most.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $years = array_values(array_unique(array_map(static fn (MonthlyEconomyRow $r): int => $r->year, $this->rows)));
        sort($years);

        return array_slice($years, -self::MAX_YEARS);
    }

    public function cell(int $year, int $month): ?MonthlyEconomyRow
    {
        foreach ($this->rows as $row) {
            if ($row->year === $year && $row->month === $month) {
                return $row;
            }
        }

        return null;
    }

    /**
     * One month across every year with data (not only the years shown),
     * weighted: Σ volume ÷ Σ distance.
     */
    public function average(int $month): MonthlyEconomyRow
    {
        $volume = '0';
        $distance = '0';
        foreach ($this->rows as $row) {
            if ($row->month === $month) {
                $volume = Decimal::add($volume, $row->volume);
                $distance = Decimal::add($distance, $row->distanceKm);
            }
        }

        return new MonthlyEconomyRow(0, $month, $volume, $distance);
    }

    /**
     * The card is hidden until some month has a figure.
     */
    public function hasFigures(): bool
    {
        for ($month = 1; $month <= 12; $month++) {
            if ($this->average($month)->hasFigure()) {
                return true;
            }
        }

        return false;
    }
}
