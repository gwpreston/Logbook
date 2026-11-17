<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `vehicle_labels(vehicles, registration_shown)` for the vehicle pickers
 * (spec.md §7.1 *Telling same-named vehicles apart*, #629): a label per
 * vehicle id, from `VehicleLabels`. Pass `true` where the picker already
 * shows each registration beside the name (#639).
 */
final class VehicleLabelsTwigExtension extends AbstractExtension
{
    public function __construct(private readonly VehicleLabels $labels)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vehicle_labels', $this->labels->labels(...)),
        ];
    }
}
