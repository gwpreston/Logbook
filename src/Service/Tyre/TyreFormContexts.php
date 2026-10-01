<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;

/**
 * What a tyre change form may choose from (spec.md §7.17): the vehicle's
 * tyres as they are, its sets and the service records a change may link.
 * The forms, the API's tread check and Ask's draft use the same one.
 */
final readonly class TyreFormContexts
{
    public function __construct(
        private TyreService $tyres,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param DateTimeImmutable $on the change's date (for the link candidates)
     */
    public function for(
        Vehicle $vehicle,
        DateTimeImmutable $today,
        DateTimeImmutable $on,
        ?int $currentLink = null,
    ): TyreFormContext {
        $fitted = [];
        $stored = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            if ($tyre->isFitted() && $tyre->position !== null) {
                $fitted[$tyre->position->value] = $tyre;
            } elseif ($tyre->isStored()) {
                $stored[] = $tyre;
            }
        }
        $ordered = [];
        foreach ($vehicle->data->type->tyrePositions() as $position) {
            if (isset($fitted[$position->value])) {
                $ordered[$position->value] = $fitted[$position->value];
            }
        }
        $maintenance = $this->features->isEnabled(Feature::Maintenance);
        $links = $maintenance ? $this->tyres->linkCandidates($vehicle, $on, $currentLink) : [];

        return new TyreFormContext(
            positions: $vehicle->data->type->tyrePositions(),
            fitted: $ordered,
            stored: $stored,
            setIds: array_map(static fn ($s): int => $s->id, $this->tyres->sets($vehicle)),
            linkIds: array_map(static fn (MaintenanceEntry $e): int => $e->id, $links),
            today: $today,
            maintenance: $maintenance,
            linkIdsWithOdometer: array_values(array_map(
                static fn (MaintenanceEntry $e): int => $e->id,
                array_filter($links, static fn (MaintenanceEntry $e): bool => $e->data->odometerKm !== null),
            )),
        );
    }
}
