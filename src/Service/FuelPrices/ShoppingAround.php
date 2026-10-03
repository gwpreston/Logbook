<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * The Fuel tab's *Shopping around* line (spec.md §7.34): the sum of the
 * last 12 months' after-fill-up comparisons.
 */
final readonly class ShoppingAround
{
    public function __construct(
        public int $fillUps,
        /** Better off by (negative: worse off). */
        public string $total,
        /** Every comparison counted the extra driving. */
        public bool $withDistance,
        public string $currency,
    ) {
    }
}
