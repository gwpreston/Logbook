<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;

/**
 * A report's figures for the vehicles that use one currency. Amounts in
 * different currencies are never added up or converted (spec.md §7.7).
 */
final readonly class CurrencyReport
{
    /**
     * @param list<GroupTotal> $groups every group, in CostGroup order
     * @param list<MonthTotal> $months every month of the period, oldest first
     * @param list<VehicleCost> $vehicles biggest spender first
     */
    public function __construct(
        public string $currency,
        public Money $total,
        public int $count,
        public array $groups,
        public array $months,
        public array $vehicles,
        /** Kilometres driven by these vehicles in the period, or null. */
        public ?string $distanceKm,
        /** Running costs ÷ distance (money per km, canonical decimal), or null. */
        public ?string $costPerKm,
        /** Running costs ÷ months: build spend is left out (#419). */
        public Money $averagePerMonth,
        /** The total less build spend (Phase 50.1): what every rate divides. */
        public ?Money $running = null,
    ) {
    }

    /**
     * The total less build spend, what every rate divides.
     */
    public function runningTotal(): Money
    {
        return $this->running ?? $this->total;
    }

    /**
     * Build spend is in the total but not in the rates (spec.md §7.7): the
     * report says so beside them.
     */
    public function hasBuild(): bool
    {
        return !$this->group(CostGroup::Build)->isZero();
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    public function group(CostGroup $group): Money
    {
        foreach ($this->groups as $total) {
            if ($total->group === $group) {
                return $total->amount;
            }
        }

        return Money::zero($this->currency);
    }

    /**
     * The groups with something spent, for legends.
     *
     * @return list<GroupTotal>
     */
    public function spentGroups(): array
    {
        return array_values(array_filter($this->groups, static fn (GroupTotal $g): bool => !$g->amount->isZero()));
    }

    /**
     * The biggest month, for scaling bars (never zero, so it can be divided by).
     */
    public function maxMonthMicros(): int
    {
        return max([1, ...array_map(static fn (MonthTotal $m): int => $m->total->micros, $this->months)]);
    }
}
