<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Support\Money\Money;

/**
 * The Fuel stations page's saving banner (spec.md §7.33, #196): the
 * cheapest listed station against what the user has paid on average, for
 * a tank. Listed price only: the trip there is not counted.
 */
final readonly class NearSaving
{
    public function __construct(
        public NearRow $row,
        /** Average paid per unit over the last 12 months, in the provider's currency. */
        public string $averagePaid,
        public Money $perTank,
        /** The tank is the 40 L default. */
        public bool $fillAssumed,
    ) {
    }
}
