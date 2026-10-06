<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\PlateStyle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * For `ui.plate()` (spec.md §8 *Registration plate*): `plate_style(vehicle)`
 * is the style of the vehicle's owner ('gb' or 'neutral'), and
 * `plate_text(registration)` what the plate shows.
 */
final class PlateTwigExtension extends AbstractExtension
{
    public function __construct(private readonly PlateStyleResolver $resolver)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plate_style', fn (Vehicle $vehicle): string => $this->resolver->forVehicle($vehicle)->value),
            new TwigFunction('plate_text', PlateStyle::text(...)),
        ];
    }
}
