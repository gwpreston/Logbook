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
    public function __construct(
        /** Its first day. */
        public DateTimeImmutable $month,
        public Money $planned,
        public Money $fuel,
        public int $unknown = 0,
    ) {
    }

    public function total(): Money
    {
        return $this->planned->add($this->fuel);
    }
}
