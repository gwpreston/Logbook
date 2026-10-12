<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;

/**
 * A currency report's amounts as shown (spec.md §8 *Shown parts add up*,
 * #403): the total rounded once to the minor unit, the groups and the
 * vehicles by largest remainder, and the months × groups table rounded under
 * control, so every list, row and column adds up to the total shown with it.
 * The groups are the table's columns, so the *By group* list and the
 * table's footer always agree. Display only: CurrencyReport keeps the exact
 * sums.
 */
final readonly class ShownReport
{
    /**
     * @param array<string, Money> $groups CostGroup value → shown amount, every group
     * @param array<string, Money> $months Y-m → shown total
     * @param array<string, array<string, Money>> $cells Y-m → CostGroup value → shown amount
     * @param list<Money> $vehicles in CurrencyReport::$vehicles order
     */
    private function __construct(
        public Money $total,
        private array $groups,
        private array $months,
        private array $cells,
        private array $vehicles,
    ) {
    }

    /**
     * @param list<GroupTotal> $groups every group
     * @param list<MonthTotal> $months
     * @param list<VehicleCost> $vehicles
     */
    public static function of(Money $total, array $groups, array $months, array $vehicles): self
    {
        $exact = [];
        foreach ($groups as $group) {
            $exact[$group->group->value] = $group->amount;
        }
        [$shownTotal, $byGroup] = ShownAmounts::parts($total, $exact);

        $grid = [];
        foreach ($months as $month) {
            $grid[$month->month->format('Y-m')] = $month->groups;
        }
        $cells = [];
        $byMonth = [];
        if ($grid !== []) {
            $shown = ShownAmounts::grid($grid, $total->currency, $byGroup);
            $byGroup = $shown['columns'];
            $cells = $shown['cells'];
            $byMonth = $shown['rows'];
        }
        [, $byVehicle] = ShownAmounts::parts($total, array_map(static fn (VehicleCost $v): Money => $v->total, $vehicles));

        return new self($shownTotal, $byGroup, $byMonth, $cells, array_values($byVehicle));
    }

    public function group(CostGroup $group): Money
    {
        return $this->groups[$group->value] ?? Money::zero($this->total->currency);
    }

    public function month(MonthTotal $month): Money
    {
        return $this->months[$month->month->format('Y-m')] ?? Money::zero($this->total->currency);
    }

    public function cell(MonthTotal $month, CostGroup $group): Money
    {
        return $this->cells[$month->month->format('Y-m')][$group->value] ?? Money::zero($this->total->currency);
    }

    public function vehicle(int $index): Money
    {
        return $this->vehicles[$index] ?? Money::zero($this->total->currency);
    }
}
