<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\InspectionRules;

/**
 * The one-time *First MOT* prompt for vehicles already in the garage
 * before 2.1.0 (spec.md §7.1 *First MOT prompt*), and the owner locale the
 * suggestion comes from. Settled vehicles are a user-scoped setting of the
 * vehicle's owner: *Set it*, *Not needed* and saving the vehicle form with
 * the field on it each settle it, for everyone who manages the vehicle.
 */
final readonly class FirstInspectionPrompt
{
    private const string SETTLED = 'vehicles.first_inspection_prompted';

    public function __construct(
        private SettingRepository $settings,
        private FirstInspection $firstInspection,
        private FeatureToggles $features,
        private VehicleAccess $access,
        private UserDirectory $directory,
    ) {
    }

    /**
     * The locale the vehicle's suggestion and hint follow: its owner's.
     */
    public function ownerLocale(User $viewer, Vehicle $vehicle): string
    {
        $owner = $vehicle->userId === $viewer->id ? $viewer : $this->directory->find($vehicle->userId);

        return ($owner ?? $viewer)->preferences->locale;
    }

    /**
     * The suggested date when the card should show to $viewer, else null.
     *
     * @param DateTimeImmutable $today today's calendar date (LocalTime::today())
     */
    public function suggestion(User $viewer, Vehicle $vehicle, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $data = $vehicle->data;
        if (
            !$this->features->isEnabled(Feature::Compliance)
            || $vehicle->isArchived()
            || $data->firstInspectionDueOn !== null
            || $data->firstRegisteredOn === null
            || !$this->access->can($viewer, VehicleAbility::Manage, $vehicle)
            || $this->isSettled($vehicle)
            || $this->firstInspection->vehicleHasCertificate($vehicle)
        ) {
            return null;
        }

        return InspectionRules::suggest($this->ownerLocale($viewer, $vehicle), $data->firstRegisteredOn, $today);
    }

    public function isSettled(Vehicle $vehicle): bool
    {
        return in_array($vehicle->id, $this->settled($vehicle->userId), true);
    }

    /**
     * Never show the card for this vehicle again.
     */
    public function settle(Vehicle $vehicle): void
    {
        $ids = $this->settled($vehicle->userId);
        if (in_array($vehicle->id, $ids, true)) {
            return;
        }
        $ids[] = $vehicle->id;
        $this->settings->save(self::SETTLED, $ids, SettingScope::User, $vehicle->userId);
    }

    /**
     * @return list<int>
     */
    private function settled(int $ownerId): array
    {
        $value = $this->settings->find(self::SETTLED, SettingScope::User, $ownerId)?->value;

        return is_array($value) ? array_values(array_filter($value, is_int(...))) : [];
    }
}
