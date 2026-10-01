<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;

/**
 * Matches a document to one of the vehicles the user can log to (spec.md
 * §7.27 *Vehicle*): the registration exactly (upper case, spaces and
 * dashes removed); else make and model if exactly one vehicle has them;
 * else the vehicle chosen beforehand; else none, and the user picks. A
 * registration that differs from the chosen vehicle's is a mismatch.
 */
final readonly class VehicleMatcher
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleAccess $access,
    ) {
    }

    /**
     * @return list<Vehicle> the active vehicles the user may log to
     */
    public function candidates(User $user): array
    {
        $vehicles = $this->vehicles->listByIds($this->access->visibleVehicleIds($user, VehicleScope::Active));

        return array_values(array_filter(
            $vehicles,
            fn (Vehicle $v): bool => $this->access->can($user, VehicleAbility::Log, $v),
        ));
    }

    public function match(User $user, Extraction $extraction, ?int $chosenId): VehicleMatch
    {
        $candidates = $this->candidates($user);
        $chosen = null;
        foreach ($candidates as $vehicle) {
            if ($vehicle->id === $chosenId) {
                $chosen = $vehicle;
            }
        }

        $printed = $extraction->value('registration');
        $plate = $printed === null ? '' : self::plate($printed);
        $byPlate = null;
        if ($plate !== '') {
            foreach ($candidates as $vehicle) {
                if ($vehicle->data->registration !== null && self::plate($vehicle->data->registration) === $plate) {
                    $byPlate = $vehicle;
                    break;
                }
            }
        }

        if ($chosen !== null) {
            $differs = $plate !== ''
                && $chosen->data->registration !== null
                && self::plate($chosen->data->registration) !== $plate;

            return $differs ? new VehicleMatch($chosen, $printed, $byPlate) : new VehicleMatch($chosen);
        }
        if ($byPlate !== null) {
            return new VehicleMatch($byPlate);
        }

        $byName = $this->byMakeAndModel($candidates, $extraction);
        if ($byName !== null) {
            return new VehicleMatch($byName);
        }

        // One vehicle: it is the one, flagged when the document names another plate.
        if (count($candidates) === 1) {
            $only = $candidates[0];
            $differs = $plate !== '' && $only->data->registration !== null;

            return new VehicleMatch($only, $differs ? $printed : null);
        }

        return new VehicleMatch(null);
    }

    public static function plate(string $registration): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($registration));
    }

    /**
     * @param list<Vehicle> $candidates
     */
    private function byMakeAndModel(array $candidates, Extraction $extraction): ?Vehicle
    {
        $words = mb_strtolower(trim(implode(' ', array_filter([
            $extraction->value('make_model'),
            $extraction->value('make'),
            $extraction->value('model'),
        ]))));
        if ($words === '') {
            return null;
        }
        $found = array_values(array_filter(
            $candidates,
            static fn (Vehicle $v): bool => $v->data->make !== '' && $v->data->model !== ''
                && str_contains($words, mb_strtolower($v->data->make))
                && str_contains($words, mb_strtolower($v->data->model)),
        ));

        return count($found) === 1 ? $found[0] : null;
    }
}
