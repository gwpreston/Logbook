<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * *Cheapest near me* (spec.md §7.34), answered on this server from the
 * synced list: a bounding box in SQL, then haversine; removed and
 * temporarily closed stations left out, and prices older than 48 hours
 * unless asked; each station's effective cost for the vehicle, and the
 * sum against the nearest station selling the grade.
 */
final readonly class CheapestNear
{
    public const array RADII = [2, 5, 10, 20];
    public const int DEFAULT_RADIUS = 5;
    public const int MAX_ROWS = 50;

    public function __construct(
        private FuelPriceConfig $config,
        private ProviderStationRepository $providerStations,
        private StationRepository $stations,
        private VehicleFuelProfiles $profiles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null while no bulk provider is enabled
     */
    public function search(
        NearOrigin $origin,
        Vehicle $vehicle,
        ?FuelGrade $grade,
        float $radiusKm,
        bool $includeOlder = false,
        NearSort $sort = NearSort::Effective,
        ?int $limit = null,
    ): ?NearResult {
        $provider = $this->config->provider();
        if (!$provider instanceof BulkPriceProvider) {
            return null;
        }
        $now = $this->clock->now();
        $profile = $this->profiles->for($vehicle, $now);
        $grade ??= $profile->grade ?? FuelGrade::E10_95;
        $code = $provider->code();

        $found = array_values(array_filter(
            $this->providerStations->nearby($code, $origin->latitude, $origin->longitude, $radiusKm),
            static fn (array $hit): bool => $hit['station']->isOpen(),
        ));
        $prices = $this->providerStations->prices(
            array_map(static fn (array $hit): int => $hit['station']->id, $found),
            $grade,
        );
        $linked = [];
        foreach ($this->stations->linked($code) as $station) {
            $linked[$station->link->ref ?? ''] = $station;
        }

        /** @var list<array{station: ProviderStation, km: float, listed: \Logbook\Domain\FuelPrices\ListedPrice, fresh: bool, cost: EffectiveCost}> $priced */
        $priced = [];
        foreach ($found as ['station' => $station, 'km' => $km]) {
            $listed = $prices[$station->id][$grade->value] ?? null;
            if ($listed === null) {
                continue;
            }
            $fresh = $listed->isFresh($now);
            if (!$fresh && !$includeOlder) {
                continue;
            }
            $priced[] = [
                'station' => $station,
                'km' => $km,
                'listed' => $listed,
                'fresh' => $fresh,
                'cost' => EffectiveCost::of($km, $listed->price, $profile),
            ];
        }

        // `nearby` is nearest first, so the first priced one is the nearest.
        $nearestCost = $priced[0]['cost'] ?? null;
        $rows = [];
        foreach ($priced as $index => $hit) {
            $rows[] = new NearRow(
                $hit['station'],
                $linked[$hit['station']->ref()] ?? null,
                $hit['listed'],
                $hit['fresh'],
                $hit['cost'],
                $index === 0 || $nearestCost === null ? null : WorthIt::compare($nearestCost, $hit['cost']),
            );
        }
        $nearest = $rows[0] ?? null;

        usort($rows, static fn (NearRow $a, NearRow $b): int => match ($sort) {
            NearSort::Effective => Decimal::compare($a->cost->total, $b->cost->total) ?: $a->cost->km <=> $b->cost->km,
            NearSort::Price => Decimal::compare($a->listed->price, $b->listed->price) ?: $a->cost->km <=> $b->cost->km,
            NearSort::Distance => $a->cost->km <=> $b->cost->km,
        });

        return new NearResult(
            $provider,
            $origin,
            $profile,
            $grade,
            $radiusKm,
            $sort,
            $includeOlder,
            array_slice($rows, 0, $limit ?? self::MAX_ROWS),
            count($rows),
            $nearest,
            $this->providerStations->lastSynced($code),
        );
    }

    /**
     * A Logbook station's provider station and its link, or none.
     */
    public function providerStationFor(Station $station): ?ProviderStation
    {
        $provider = $this->config->provider();
        if ($provider === null || $station->link === null || $station->link->provider !== $provider->code()) {
            return null;
        }

        return $this->providerStations->findByRef($provider->code(), $station->link->ref);
    }
}
