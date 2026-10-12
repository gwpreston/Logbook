<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;

/**
 * Cost of ownership's amounts as shown on the vehicle's card (spec.md §8
 * *Shown parts add up*, #403): the groups and the payouts add up to the
 * running costs shown, the running costs and depreciation to the total shown,
 * and a month's running costs and depreciation to its total. Each total is
 * rounded once; display only.
 */
final readonly class ShownOwnership
{
    /**
     * @param array<string, Money> $groups CostGroup value → shown amount
     */
    private function __construct(
        public Money $running,
        private array $groups,
        /** The payouts as taken off (a positive amount), or null. */
        public ?Money $payouts,
        public ?Money $depreciation,
        public ?Money $total,
        public ?Money $runningPerMonth,
        public ?Money $depreciationPerMonth,
        public ?Money $perMonth,
    ) {
    }

    public static function of(OwnershipCost $cost): self
    {
        $parts = [];
        foreach ($cost->spentGroups() as $group) {
            $parts[$group->group->value] = $group->amount;
        }
        if ($cost->payouts !== null) {
            $parts['payouts'] = Money::zero($cost->currency)->subtract($cost->payouts);
        }
        [$running, $shown] = ShownAmounts::parts($cost->running, $parts);
        $payouts = isset($shown['payouts']) ? Money::zero($cost->currency)->subtract($shown['payouts']) : null;
        unset($shown['payouts']);

        // The running costs are already shown: depreciation is what makes up the total.
        $total = $cost->total === null ? null : ShownAmounts::round($cost->total);
        $depreciation = $total === null || $cost->depreciationCost === null ? null : $total->subtract($running);

        $perMonth = $cost->perMonth === null ? null : ShownAmounts::round($cost->perMonth);
        $runningPerMonth = $cost->runningPerMonth === null ? null : ShownAmounts::round($cost->runningPerMonth);
        $depreciationPerMonth = null;
        if ($cost->perMonth !== null && $cost->runningPerMonth !== null && $cost->depreciationPerMonth !== null) {
            [$perMonth, [$runningPerMonth, $depreciationPerMonth]] = ShownAmounts::parts(
                $cost->perMonth,
                [$cost->runningPerMonth, $cost->depreciationPerMonth],
            );
        }

        return new self($running, $shown, $payouts, $depreciation, $total, $runningPerMonth, $depreciationPerMonth, $perMonth);
    }

    /** A gain in value as shown (a positive amount), or null without one. */
    public function gain(): ?Money
    {
        return $this->depreciation !== null && $this->depreciation->isNegative()
            ? Money::zero($this->depreciation->currency)->subtract($this->depreciation)
            : null;
    }

    public function group(CostGroup $group): Money
    {
        return $this->groups[$group->value] ?? Money::zero($this->running->currency);
    }
}
