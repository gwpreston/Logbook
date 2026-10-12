<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;

/**
 * Cost of ownership's amounts as shown on the vehicle's card (spec.md §8
 * *Shown parts add up*, #403): the running costs and depreciation add up
 * to the total shown, the groups and the payouts to the running costs
 * shown, and a month's running costs and depreciation to its total. The
 * top total is rounded once and the rest shared out from it, so every
 * figure is within a minor unit of its exact amount; display only.
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
        private bool $isGain = false,
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
        // From the top: the total, then the running costs and depreciation, then the groups.
        $total = null;
        $depreciation = null;
        if ($cost->total !== null && $cost->depreciationCost !== null) {
            $levels = [$cost->running, $cost->depreciationCost];
            [$total, [$running, $depreciation]] = ShownAmounts::parts($cost->total, $levels);
        } else {
            $total = $cost->total === null ? null : ShownAmounts::round($cost->total);
            $running = ShownAmounts::round($cost->running);
        }
        $shown = ShownAmounts::to($running, $parts);
        $payouts = isset($shown['payouts']) ? Money::zero($cost->currency)->subtract($shown['payouts']) : null;
        unset($shown['payouts']);

        $perMonth = $cost->perMonth === null ? null : ShownAmounts::round($cost->perMonth);
        $runningPerMonth = $cost->runningPerMonth === null ? null : ShownAmounts::round($cost->runningPerMonth);
        $depreciationPerMonth = null;
        if ($cost->perMonth !== null && $cost->runningPerMonth !== null && $cost->depreciationPerMonth !== null) {
            [$perMonth, [$runningPerMonth, $depreciationPerMonth]] = ShownAmounts::parts(
                $cost->perMonth,
                [$cost->runningPerMonth, $cost->depreciationPerMonth],
            );
        }

        $isGain = $cost->depreciationCost !== null && $cost->depreciationCost->isNegative();

        return new self(
            $running,
            $shown,
            $payouts,
            $depreciation,
            $total,
            $runningPerMonth,
            $depreciationPerMonth,
            $perMonth,
            $isGain,
        );
    }

    /** A gain in value as shown (a positive amount, 0 when under half a unit), or null without one. */
    public function gain(): ?Money
    {
        return $this->isGain && $this->depreciation !== null
            ? Money::zero($this->depreciation->currency)->subtract($this->depreciation)
            : null;
    }

    public function group(CostGroup $group): Money
    {
        return $this->groups[$group->value] ?? Money::zero($this->running->currency);
    }
}
