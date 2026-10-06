<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Support\Money\Money;

/**
 * A cost group's spend and its whole-percentage share of its currency's
 * total (the shares of a section add up to 100).
 */
final readonly class GroupShare
{
    public function __construct(
        public CostGroup $group,
        public Money $amount,
        public int $percent,
    ) {
    }
}
