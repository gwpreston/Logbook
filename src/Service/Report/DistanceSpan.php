<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;

/**
 * The two readings a period's distance is the difference of (spec.md §7.7;
 * PeriodDistance::span()): kept so the working (§8) can name them.
 */
final readonly class DistanceSpan
{
    public function __construct(
        /** The reading the distance starts from: the last before the period, else the first in it. */
        public DateTimeImmutable $startOn,
        public string $startKm,
        /** The last reading in the period. */
        public DateTimeImmutable $endOn,
        public string $endKm,
        /** End − start, kilometres (canonical decimal), above zero. */
        public string $km,
    ) {
    }
}
