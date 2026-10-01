<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `write_off(vehicle)`: the vehicle's latest write-off category, for the
 * badge in its header (spec.md §7.29 *Overview*); null when none is
 * recorded or the module is off. A write-off is a fact about the vehicle,
 * not a claim detail, so everyone who can view it sees the badge.
 */
final class IncidentTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly FeatureToggles $features,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('write_off', function (Vehicle $vehicle): ?WriteOffCategory {
                if (!$this->features->isEnabled(Feature::Incidents)) {
                    return null;
                }
                foreach ($this->incidents->listForVehicles([$vehicle->id]) as $incident) {
                    if ($incident->data->writeOff->isWrittenOff()) {
                        return $incident->data->writeOff;
                    }
                }

                return null;
            }),
        ];
    }
}
