<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Service\Vehicle\VehicleAge;

/**
 * A tyre with its derived figures (spec.md §7.17), for the Tyres tab, the
 * overview card, the print header and CSV. Nothing here is stored.
 */
final readonly class TyreView
{
    public function __construct(
        public Tyre $tyre,
        public TyreDistanceFigure $distance,
        /** From the DOT date, on the owner's today; null without one. */
        public ?VehicleAge $age = null,
        /** Set when its first change was `existing`: distance counts "since" this date. */
        public ?DateTimeImmutable $since = null,
        /** When it was retired. */
        public ?DateTimeImmutable $retiredOn = null,
        /** Retired and costed: its share of the fitting's cost per km, in the vehicle's currency. */
        public ?string $costPerKm = null,
    ) {
    }
}
