<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * One vehicle's *Fuel saving* (spec.md §7.8, Phase 42) and the figures
 * behind it, canonical: litres and prices per litre as exact decimals in
 * the vehicle's currency. The insight and `computed_insights` show it.
 */
final readonly class FuelSavingFigure
{
    public function __construct(
        public Vehicle $vehicle,
        public FuelGrade $grade,
        public string $currency,
        /** yearly volume × (usual price − the cheapest's effective price per unit). */
        public string $yearlySaving,
        public string $yearlyLitres,
        /** The months of history the yearly volume was scaled from (#357), or null when not scaled. */
        public ?int $scaledFromMonths,
        public string $usualPrice,
        /** The usual price is the 30-day average paid, not a listed price (#352). */
        public bool $usualFromAverage,
        /** The usual station's name; null when the vehicle has none. */
        public ?string $usualName,
        /** The cheapest's effective cost ÷ the usual fill: the detour counted. */
        public string $cheapestPerUnit,
        public string $cheapestName,
        /** The usual fill is *Cheapest near me*'s assumed 40 L. */
        public bool $fillAssumed,
        /** The drive there is counted (the vehicle has an economy). */
        public bool $detourCounted,
        /** Where the cheapest was searched from (the viewer's first place). */
        public Place $place,
    ) {
    }
}
