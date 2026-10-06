<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Support\Money\Money;

/**
 * One currency's part of the *Expense breakdown* widget.
 */
final readonly class BreakdownSection
{
    /**
     * @param list<GroupShare> $rows the groups with something spent, in CostGroup order
     */
    public function __construct(
        public string $currency,
        public Money $total,
        public array $rows,
    ) {
    }
}
