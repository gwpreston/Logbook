<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * One currency's figures for one month: the known planned costs (overdue
 * items count in this month), the fuel estimate, and how many items have no
 * known cost.
 */
final readonly class ForecastMonthTotal
{
    /**
     * @param list<ForecastItem> $costed the items with a known cost that $planned adds up
     *                                   (a finance line's payments are in $financeSpread)
     * @param list<ForecastItem> $unknownItems the items counted in $unknown
     */
    public function __construct(
        /** Its first day. */
        public DateTimeImmutable $month,
        public Money $planned,
        public Money $fuel,
        public int $unknown = 0,
        public array $costed = [],
        public array $unknownItems = [],
        /** What a finance agreement's payments in this month add to $planned (spec.md §7.32). */
        public ?Money $financeSpread = null,
    ) {
    }

    public function total(): Money
    {
        return $this->planned->add($this->fuel);
    }
}
