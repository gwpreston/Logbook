<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;

/**
 * One currency's *Coming up* table as shown (spec.md §8 *Shown parts add
 * up*, #407): months × (planned, fuel) rounded under control, so each
 * month's planned and fuel add up to its total and each column to the
 * year's, and *Next 3 months* is the sum of its three shown months. The
 * summary cards, the month lines and the dashboard's *Coming up* lines
 * show the same amounts. Display only:
 * the exact sums are unchanged.
 */
final readonly class ShownForecastTotals
{
    /**
     * @param list<Money> $planned per month
     * @param list<Money> $fuel per month
     * @param list<Money> $months each month's total
     */
    private function __construct(
        public array $planned,
        public array $fuel,
        public array $months,
        public Money $plannedTotal,
        public Money $fuelTotal,
        public Money $total,
        public Money $soon,
        /** The fuel in *Next 3 months*, as its three months show it. */
        public Money $soonFuel,
    ) {
    }

    public static function of(ForecastTotals $totals): self
    {
        $zero = Money::zero($totals->currency);
        $cells = [];
        foreach ($totals->months as $i => $month) {
            $cells[$i] = ['planned' => $month->planned, 'fuel' => $month->fuel];
        }
        $grid = ShownAmounts::grid($cells, $totals->currency);

        $planned = [];
        $fuel = [];
        $months = [];
        $soon = $zero;
        $soonFuel = $zero;
        foreach (array_keys($totals->months) as $i) {
            $planned[] = $grid['cells'][$i]['planned'];
            $fuel[] = $grid['cells'][$i]['fuel'];
            $months[] = $grid['rows'][$i];
            if ($i < ForecastTotals::SOON_MONTHS) {
                $soon = $soon->add($grid['rows'][$i]);
                $soonFuel = $soonFuel->add($grid['cells'][$i]['fuel']);
            }
        }

        return new self(
            $planned,
            $fuel,
            $months,
            $grid['columns']['planned'] ?? $zero,
            $grid['columns']['fuel'] ?? $zero,
            $grid['total'],
            $soon,
            $soonFuel,
        );
    }
}
