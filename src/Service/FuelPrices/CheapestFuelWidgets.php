<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Station\Place;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\SettingRepository;

/**
 * Builds the *Cheapest fuel* widget (spec.md §7.34): near the user's chosen
 * place (the user setting `dashboard.cheapest_fuel`), else their first
 * place; for the dashboard's selected vehicle, else the most recently
 * filled one; at the default radius and the vehicle's usual grade.
 */
final readonly class CheapestFuelWidgets
{
    public const string SETTING = 'dashboard.cheapest_fuel';
    public const int ROWS = 3;

    public function __construct(
        private CheapestNear $near,
        private NearForm $form,
        private SettingRepository $settings,
    ) {
    }

    public function build(User $user, ?Vehicle $selected): CheapestFuelWidget
    {
        $places = $this->form->places($user);
        $chosen = $this->settings->find(self::SETTING, SettingScope::User, $user->id)?->value;
        $placeId = is_array($chosen) && is_int($chosen['place'] ?? null) ? $chosen['place'] : null;
        $place = null;
        foreach ($places as $candidate) {
            if ($candidate->id === $placeId) {
                $place = $candidate;
            }
        }
        $place ??= $places[0] ?? null;
        $vehicles = $this->form->vehicles($user);
        $vehicle = $selected !== null
            && in_array($selected->id, array_map(static fn (Vehicle $v): int => $v->id, $vehicles), true)
            ? $selected
            : ($vehicles[0] ?? null);

        $result = null;
        if ($place !== null && $vehicle !== null) {
            $result = $this->near->search(
                NearOrigin::place(
                    $place->id,
                    $place->data->name,
                    (float) $place->data->latitude,
                    (float) $place->data->longitude,
                ),
                $vehicle,
                null,
                $user->preferences->distanceUnit->toKm((float) CheapestNear::DEFAULT_RADIUS),
                limit: self::ROWS,
            );
        }

        return new CheapestFuelWidget($places, $place, $vehicle, $result);
    }

    /**
     * Save the widget's place (one of the user's own).
     */
    public function choosePlace(User $user, int $placeId): bool
    {
        $ids = array_map(static fn (Place $p): int => $p->id, $this->form->places($user));
        if (!in_array($placeId, $ids, true)) {
            return false;
        }
        $this->settings->save(self::SETTING, ['place' => $placeId], SettingScope::User, $user->id);

        return true;
    }
}
