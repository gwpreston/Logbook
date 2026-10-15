<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Where a part's warranty stands (spec.md §7.39, #427): its end date (months
 * from the purchase date or the first fitting) and end mileage (a distance
 * from the first fitting's mileage), and whether every set limit still holds.
 */
final readonly class PartWarranty
{
    public function __construct(
        /** Calendar date the time limit ends; null without one or without its start. */
        public ?DateTimeImmutable $until,
        /** Odometer (km) the distance limit ends at; null without one or without a fitted mileage. */
        public ?string $untilKm,
        public bool $active,
        /** The day it ended, when it ended by date. */
        public ?DateTimeImmutable $endedOn = null,
    ) {
    }

    /**
     * Null when the part has no warranty.
     *
     * @param list<PartFitting> $fittings oldest first
     * @param string|null $currentKm the vehicle's latest reading
     */
    public static function of(Part $part, array $fittings, ?string $currentKm, DateTimeImmutable $today): ?self
    {
        $data = $part->data;
        if (!$data->hasWarranty()) {
            return null;
        }
        $first = PartTimeline::first($fittings);
        $start = match ($data->warrantyFrom ?? ($data->boughtOn !== null ? WarrantyStart::Purchase : WarrantyStart::Fitting)) {
            WarrantyStart::Purchase => $data->boughtOn ?? $first?->fittedOn,
            WarrantyStart::Fitting => $first?->fittedOn,
        };
        $until = $data->warrantyMonths === null || $start === null
            ? null
            : LocalTime::addMonths($start, $data->warrantyMonths);
        $untilKm = $data->warrantyKm === null || $first?->fittedOdometerKm === null
            ? null
            : Decimal::add($first->fittedOdometerKm, $data->warrantyKm);

        $byDate = $until === null || $today <= $until;
        // The distance counts only while the part is on the car: off it, the limit is read at
        // the mileage it came off at (unknown: it can't be measured, so it never ends it).
        $measuredAt = PartTimeline::open($fittings) !== null ? $currentKm : PartTimeline::last($fittings)?->removedOdometerKm;
        $byKm = $untilKm === null || $measuredAt === null || Decimal::compare($measuredAt, $untilKm) <= 0;
        // A limit that can't be measured yet (no start, no fitted mileage) never ends it.
        return new self($until, $untilKm, $byDate && $byKm, !$byDate ? $until : null);
    }
}
