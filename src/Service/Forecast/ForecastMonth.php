<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;

/**
 * One month of the horizon with the items falling due in it, by date.
 */
final readonly class ForecastMonth
{
    /**
     * @param list<ForecastItem> $items
     */
    public function __construct(
        /** Its first day. */
        public DateTimeImmutable $month,
        public array $items,
    ) {
    }
}
