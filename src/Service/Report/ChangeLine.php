<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Money\Money;

/**
 * One contribution to a year's change in cost per distance (spec.md §7.35
 * *What changed*), per kilometre and signed. The details of a fuel line add
 * up exactly to it.
 */
final readonly class ChangeLine
{
    /**
     * @param list<ChangeLine> $details
     */
    public function __construct(
        public ChangeCause $cause,
        /** Signed change per km (canonical decimal, 6 places). */
        public string $perKm,
        public ?TruePart $part = null,
        public ?EnergyKind $energy = null,
        /** Price or consumption this year against last, as a signed fraction (6 places). */
        public ?string $fraction = null,
        /** This year's distance minus last year's (km, signed), for a distance line. */
        public ?string $distanceKm = null,
        /** This year's amount minus last year's, for an amount line. */
        public ?Money $amountChange = null,
        public array $details = [],
    ) {
    }
}
