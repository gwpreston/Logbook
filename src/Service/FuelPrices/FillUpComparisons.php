<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\StationRepository;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Station\PlaceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Geo\Haversine;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * "Was it worth it?" after a fill-up, and the Fuel tab's *Shopping around*
 * (spec.md §7.34). A fill-up at a linked station is compared with the
 * vehicle's usual station (the most visited in the 12 months before it,
 * ties going to the latest visit) when that is another linked station and
 * both have a listed price in effect at the fill-up's time (#144). The
 * extra distance needs the viewer's *Home* place and both positions.
 */
final readonly class FillUpComparisons
{
    public const int SHOPPING_MINIMUM = 3;
    public const string PERIOD = '-12 months';

    public function __construct(
        private FuelPriceConfig $config,
        private ListedPrices $listed,
        private StationRepository $stations,
        private FuelService $fuel,
        private VehicleFuelProfiles $profiles,
        private PlaceService $places,
        private VehicleService $vehicles,
        private ClockInterface $clock,
    ) {
    }

    public function forEntry(User $user, Vehicle $vehicle, FuelEntry $entry): ?FillUpComparison
    {
        if (!$this->config->enabled()) {
            return null;
        }

        return $this->compare(
            $user,
            $vehicle,
            $entry,
            $this->fuel->entries($vehicle),
            $this->home($user),
        );
    }

    /**
     * The vehicle's comparisons over the last 12 months: "about £18.40
     * better off from 23 fill-ups", shown from three on.
     */
    public function shoppingAround(User $user, Vehicle $vehicle): ?ShoppingAround
    {
        if (!$this->config->enabled()) {
            return null;
        }
        $entries = $this->fuel->entries($vehicle);
        $since = $this->clock->now()->modify(self::PERIOD);
        $home = $this->home($user);
        $total = '0';
        $count = 0;
        $withDistance = true;
        foreach ($entries as $entry) {
            if ($entry->data->filledAt < $since) {
                continue;
            }
            $comparison = $this->compare($user, $vehicle, $entry, $entries, $home);
            if ($comparison === null) {
                continue;
            }
            $total = Decimal::add($total, $comparison->result());
            $withDistance = $withDistance && $comparison->hasDistance();
            $count++;
        }

        return $count >= self::SHOPPING_MINIMUM
            ? new ShoppingAround($count, Decimal::round($total, 2), $withDistance, $this->vehicles->currencyFor($user, $vehicle))
            : null;
    }

    /**
     * @param list<FuelEntry> $entries the vehicle's fill-ups
     */
    private function compare(User $user, Vehicle $vehicle, FuelEntry $entry, array $entries, ?Place $home): ?FillUpComparison
    {
        $data = $entry->data;
        if (
            $data->stationId === null || $data->grade === null || $data->fuel->kind() !== EnergyKind::Liquid
            || Decimal::compare($data->volume, '0') <= 0 || Decimal::compare($data->pricePerUnit, '0') <= 0
        ) {
            return null;
        }
        $station = $this->stations->resolve($data->stationId);
        $usualId = self::usualStationId($entries, $data->filledAt);
        if ($station === null || $station->link === null || $usualId === null) {
            return null;
        }
        $usual = $this->stations->resolve($usualId);
        if ($usual === null || $usual->id === $station->id || $usual->link === null) {
            return null;
        }
        $own = $this->listed->priceAt($station, $data->grade, $data->filledAt);
        $usualListed = $own === null ? null : $this->listed->priceAt($usual, $data->grade, $data->filledAt);
        if ($own === null || $usualListed === null) {
            return null;
        }

        $fuelSaving = Decimal::multiply(Decimal::subtract($usualListed->price, $data->pricePerUnit), $data->volume, 4);
        $extraKm = $home === null ? null : self::extraRoadKm($home, $station, $usual);
        $extraCost = null;
        $total = null;
        if ($extraKm !== null) {
            $litres = $this->profiles->for($vehicle, $this->clock->now(), $data->filledAt)->litresFor(abs($extraKm));
            if ($litres !== null) {
                $cost = Decimal::multiply($litres, $data->pricePerUnit, 4);
                $extraCost = $extraKm < 0 ? Decimal::subtract('0', $cost) : $cost;
                $total = Decimal::subtract($fuelSaving, $extraCost);
            } else {
                $extraKm = null;
            }
        }

        return new FillUpComparison(
            $usual,
            $usualListed,
            $own,
            $fuelSaving,
            $extraKm,
            $extraCost,
            $total,
            $this->vehicles->currencyFor($user, $vehicle),
        );
    }

    /**
     * The station of most fill-ups in the 12 months before $at (not
     * counting fill-ups at or after it); ties go to the latest visit.
     *
     * @param list<FuelEntry> $entries
     */
    public static function usualStationId(array $entries, DateTimeImmutable $at): ?int
    {
        $since = $at->modify(self::PERIOD);
        $visits = [];
        foreach ($entries as $entry) {
            $id = $entry->data->stationId;
            $when = $entry->data->filledAt;
            if ($id === null || $when >= $at || $when < $since) {
                continue;
            }
            $visits[$id] ??= ['count' => 0, 'last' => $when];
            $visits[$id]['count']++;
            if ($when > $visits[$id]['last']) {
                $visits[$id]['last'] = $when;
            }
        }
        if ($visits === []) {
            return null;
        }
        uasort($visits, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['last'] <=> $a['last']);

        return (int) array_key_first($visits);
    }

    /**
     * (This station's distance from Home − the usual one's) × 2 × 1.3, or
     * none without both positions.
     */
    private static function extraRoadKm(Place $home, Station $station, Station $usual): ?float
    {
        if (!$station->data->hasPosition() || !$usual->data->hasPosition()) {
            return null;
        }
        $lat = (float) $home->data->latitude;
        $lon = (float) $home->data->longitude;
        $here = Haversine::km($lat, $lon, (float) $station->data->latitude, (float) $station->data->longitude);
        $there = Haversine::km($lat, $lon, (float) $usual->data->latitude, (float) $usual->data->longitude);

        return EffectiveCost::detour($here - $there);
    }

    /**
     * The viewer's place named *Home* (case-folded), if any.
     */
    private function home(User $user): ?Place
    {
        foreach ($this->places->list($user) as $place) {
            if (StationName::normalise($place->data->name) === 'home') {
                return $place;
            }
        }

        return null;
    }
}
