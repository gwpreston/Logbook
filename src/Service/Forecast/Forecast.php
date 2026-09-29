<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * The next 12 months (spec.md §7.18): overdue items, one section per month,
 * items whose date is not known yet, each vehicle's fuel estimate and the
 * totals per currency. Derived on every read (ComingUp), never stored.
 */
final readonly class Forecast
{
    /**
     * @param list<ForecastItem> $overdue oldest first
     * @param list<ForecastMonth> $months one per horizon month, this one first
     * @param list<ForecastItem> $undated
     * @param list<FuelEstimate> $fuel one per vehicle (none with `fuel` off)
     * @param list<ForecastTotals> $totals one per currency
     */
    public function __construct(
        public ForecastHorizon $horizon,
        public array $overdue,
        public array $months,
        public array $undated,
        public array $fuel,
        public array $totals,
    ) {
    }

    public function today(): DateTimeImmutable
    {
        return $this->horizon->today;
    }

    /**
     * Every item in order: overdue, then by date, then undated.
     *
     * @return list<ForecastItem>
     */
    public function items(): array
    {
        $items = $this->overdue;
        foreach ($this->months as $month) {
            array_push($items, ...$month->items);
        }

        return [...$items, ...$this->undated];
    }

    /**
     * The next few, for the overview card and the dashboard widget.
     *
     * @return list<ForecastItem>
     */
    public function next(int $count): array
    {
        return array_slice($this->items(), 0, $count);
    }

    public function hasItems(): bool
    {
        return $this->items() !== [];
    }

    public function isEmpty(): bool
    {
        if ($this->hasItems()) {
            return false;
        }
        foreach ($this->fuel as $estimate) {
            if ($estimate->isReady()) {
                return false;
            }
        }

        return true;
    }

    public function fuelFor(Vehicle $vehicle): ?FuelEstimate
    {
        foreach ($this->fuel as $estimate) {
            if ($estimate->vehicle->id === $vehicle->id) {
                return $estimate;
            }
        }

        return null;
    }

    /**
     * Whether a month's section has anything to show.
     */
    public function hasMonthItems(): bool
    {
        foreach ($this->months as $month) {
            if ($month->items !== []) {
                return true;
            }
        }

        return false;
    }
}
