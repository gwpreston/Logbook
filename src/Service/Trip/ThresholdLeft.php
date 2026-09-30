<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * How far the claimant can drive before the lower rate ("6,418 mi until the
 * 25p rate", spec.md §7.22).
 */
final readonly class ThresholdLeft
{
    public function __construct(
        public DistanceUnit $unit,
        /** Canonical decimal in $unit; 0 once past it. */
        public string $left,
        public string $rateAfter,
        public string $currency,
    ) {
    }

    public function isPast(): bool
    {
        return Decimal::compare($this->left, '0') <= 0;
    }
}
