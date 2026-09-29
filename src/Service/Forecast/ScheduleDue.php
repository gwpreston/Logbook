<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Support\Money\Money;

/**
 * A schedule as it stands today (the same state its reminder is built from)
 * with its price last time.
 */
final readonly class ScheduleDue
{
    public function __construct(
        public ScheduleState $state,
        /** The latest completing entry's cost, when above 0. */
        public ?Money $cost = null,
    ) {
    }
}
