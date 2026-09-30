<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\Vehicle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The access policy for templates (spec.md §5 *Access policy*):
 *
 * - `can_see_costs(vehicle)`: every amount shown for a vehicle sits inside
 *   this check (ViewCosts); a test keeps it so.
 * - `can_instance('backup')`: links to install-wide pages.
 *
 * Both are false when nobody is signed in.
 */
final class AccessTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly VehicleAccess $vehicles,
        private readonly InstanceAccess $instance,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_see_costs', function (Vehicle $vehicle): bool {
                $user = $this->context->user();

                return $user !== null && $this->vehicles->can($user, VehicleAbility::ViewCosts, $vehicle);
            }),
            new TwigFunction('can_instance', function (string $ability): bool {
                $user = $this->context->user();

                return $user !== null && $this->instance->can($user, InstanceAbility::from($ability));
            }),
        ];
    }
}
