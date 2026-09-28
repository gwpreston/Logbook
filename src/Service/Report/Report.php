<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostItem;

/**
 * A finished report (spec.md §7.7): what it covers and its figures, one
 * section per currency.
 */
final readonly class Report
{
    /**
     * @param list<Vehicle> $vehicles the vehicles covered
     * @param list<CurrencyReport> $currencies at least one; more only when the vehicles use several currencies
     * @param list<CostItem> $items the costs in the period, oldest first
     */
    public function __construct(
        public ReportFilter $filter,
        /** The period with an open start resolved to the earliest cost. */
        public ReportPeriod $period,
        public array $vehicles,
        public array $currencies,
        public array $items,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function monthCount(): int
    {
        return count($this->period->months());
    }

    /**
     * The single vehicle the report is about, or null for the fleet.
     */
    public function vehicle(): ?Vehicle
    {
        return $this->filter->vehicleId !== null && count($this->vehicles) === 1 ? $this->vehicles[0] : null;
    }

    public function hasSeveralCurrencies(): bool
    {
        return count($this->currencies) > 1;
    }

    /**
     * @return list<CostItem> newest first, for lists
     */
    public function newestFirst(): array
    {
        return array_reverse($this->items);
    }
}
