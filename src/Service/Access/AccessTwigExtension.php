<?php

declare(strict_types=1);

namespace Logbook\Service\Access;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Sharing\AuthorLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The access policy for templates (spec.md §5 *Access policy*, §7.21):
 *
 * - `can_see_costs(vehicle)`: every amount shown for a vehicle sits inside
 *   this check (ViewCosts), or, for one entry's own amount, inside
 *   `can_see_amount(vehicle, entry.createdBy)` (also true for one's own
 *   entry); a test keeps it so.
 * - `can_vehicle(vehicle, 'manage')`: links and buttons that need an ability.
 * - `can_change(vehicle, entry.createdBy)`: an entry's edit and delete links.
 * - `added_by(vehicle, entry.createdBy)`: who to name, or null (see AuthorLabels).
 * - `costs_excluded()`: how many of one's active vehicles fleet cost figures
 *   leave out for want of ViewCosts ("Excludes 1 vehicle shared without costs").
 * - `can_instance('backup')`: links to install-wide pages.
 *
 * All are false (added_by null) when nobody is signed in.
 */
final class AccessTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly VehicleAccess $vehicles,
        private readonly InstanceAccess $instance,
        private readonly EntryAccess $entries,
        private readonly AuthorLabels $authors,
        private readonly VehicleRepository $repository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_see_costs', function (Vehicle $vehicle): bool {
                $user = $this->context->user();

                return $user !== null && $this->vehicles->can($user, VehicleAbility::ViewCosts, $vehicle);
            }),
            new TwigFunction('can_see_amount', function (Vehicle $vehicle, ?int $createdBy): bool {
                $user = $this->context->user();

                return $user !== null && $this->entries->canSeeAmount($user, $vehicle, $createdBy);
            }),
            new TwigFunction('can_vehicle', function (Vehicle $vehicle, string $ability): bool {
                $user = $this->context->user();

                return $user !== null && $this->vehicles->can($user, VehicleAbility::from($ability), $vehicle);
            }),
            new TwigFunction('can_change', function (Vehicle $vehicle, ?int $createdBy): bool {
                $user = $this->context->user();

                return $user !== null && $this->entries->canChange($user, $vehicle, $createdBy);
            }),
            new TwigFunction('added_by', function (Vehicle $vehicle, ?int $createdBy): ?string {
                $user = $this->context->user();

                return $user === null ? null : $this->authors->label($user, $vehicle, $createdBy);
            }),
            new TwigFunction('costs_excluded', function (): int {
                $user = $this->context->user();
                if ($user === null) {
                    return 0;
                }
                $vehicles = $this->repository->listByIds($this->vehicles->visibleVehicleIds($user, VehicleScope::Active));

                return count(array_filter(
                    $vehicles,
                    fn (Vehicle $vehicle): bool => !$this->vehicles->can($user, VehicleAbility::ViewCosts, $vehicle),
                ));
            }),
            new TwigFunction('can_instance', function (string $ability): bool {
                $user = $this->context->user();

                return $user !== null && $this->instance->can($user, InstanceAbility::from($ability));
            }),
        ];
    }
}
