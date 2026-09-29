<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * With `costs=1` only (spec.md §7.19): what the service and repair records
 * add up to, from the earliest of them. "£3,240 spent on servicing and
 * repairs since March 2021". Never purchase or sale prices, fuel, expenses,
 * valuations or ownership costs.
 */
final readonly class WorkCost
{
    public function __construct(
        public Money $total,
        public DateTimeImmutable $since,
    ) {
    }
}
