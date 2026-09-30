<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

/**
 * Part of a valued trip's distance at one rate: all of it, or the part
 * below or above the threshold of a trip that crosses it (spec.md §7.23).
 */
final readonly class ClaimLine
{
    public function __construct(
        /** Canonical decimal in the rate set's unit (3 places). */
        public string $distance,
        /** Per unit, canonical decimal. */
        public string $rate,
    ) {
    }
}
