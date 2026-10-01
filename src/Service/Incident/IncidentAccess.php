<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;

/**
 * Who may see what of an incident (spec.md §7.29 *Access*). The one place
 * that decides it: every page, export, API answer and tool asks here, then
 * reads the incident through an IncidentView.
 */
final readonly class IncidentAccess
{
    public function __construct(
        private VehicleAccess $vehicles,
        private EntryAccess $entries,
    ) {
    }

    /**
     * The fault, driver, other party, police reference, claim and notes:
     * `Manage` and `Own`, and whoever logged it.
     */
    public function seesDetails(User $user, Vehicle $vehicle, Incident $incident): bool
    {
        return $incident->createdBy === $user->id
            || $this->vehicles->can($user, VehicleAbility::ViewIncidentDetails, $vehicle);
    }

    /**
     * Its amounts (linked costs, excess, payout), as an entry's own amount.
     */
    public function seesAmounts(User $user, Vehicle $vehicle, Incident $incident): bool
    {
        return $this->entries->canSeeAmount($user, $vehicle, $incident->createdBy);
    }

    public function canChange(User $user, Vehicle $vehicle, Incident $incident): bool
    {
        return $this->entries->canChange($user, $vehicle, $incident->createdBy);
    }

    public function view(User $user, Vehicle $vehicle, Incident $incident, ?IncidentCosts $costs = null): IncidentView
    {
        return IncidentView::of(
            $incident,
            $this->seesDetails($user, $vehicle, $incident),
            $this->seesAmounts($user, $vehicle, $incident),
            $costs,
        );
    }
}
