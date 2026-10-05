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
        /** Its latest depth and, while fitted at a road position, the wear estimate (Phase 11.2). */
        public TyreWearEstimate $wear = new TyreWearEstimate(),
        /** When it reaches the owner's age limit; null without a DOT date, with the limit off, or retired. */
        public ?DateTimeImmutable $ageLimitOn = null,
        /**
         * While fitted: the date of the change that put it where it is now,
         * its latest fitting or move (Phase 33.3, #185); null when that was
         * the `existing` change, which $since already dates.
         */
        public ?DateTimeImmutable $fittedOn = null,
    ) {
    }
}
