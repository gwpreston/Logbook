<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;

/**
 * Spend in one calendar month (owner's time zone), per group.
 */
final readonly class MonthTotal
{
    /**
     * @param array<string, Money> $groups keyed by CostGroup value, every group present
     */
    public function __construct(
        /** The 1st of the month (calendar date). */
        public DateTimeImmutable $month,
        public Money $total,
        public array $groups,
    ) {
    }

    public function amount(CostGroup $group): Money
    {
        return $this->groups[$group->value];
    }
}
