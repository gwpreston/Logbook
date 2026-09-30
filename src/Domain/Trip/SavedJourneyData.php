<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

/**
 * A repeat journey as entered (spec.md §6 SavedJourney). The distance is one
 * way, in kilometres; a trip logged from it as a return doubles it.
 */
final readonly class SavedJourneyData
{
    public function __construct(
        public string $fromPlace,
        public string $toPlace,
        public string $distanceKm = '0.000',
        public bool $isReturnDefault = false,
        public ?string $purposeDefault = null,
        public bool $isBusinessDefault = true,
    ) {
    }
}
