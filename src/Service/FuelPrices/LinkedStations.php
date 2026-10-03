<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;

/**
 * Keeps linked Logbook stations' details up to date from their provider
 * station (spec.md §7.34 *Linking stations*): address, postcode, position,
 * opening hours and the liquid grades sold, unless *Keep my details* is
 * ticked. The name and brand are never changed; a public charger's charging
 * grades are kept.
 */
final readonly class LinkedStations
{
    public function __construct(
        private StationRepository $stations,
        private ProviderStationRepository $providerStations,
    ) {
    }

    /**
     * Refresh every station linked to the provider.
     *
     * @return int stations whose details changed
     */
    public function refreshAll(string $provider, DateTimeImmutable $now): int
    {
        $linked = $this->stations->linked($provider);
        $refs = array_map(static fn (Station $s): string => $s->link->ref ?? '', $linked);
        $found = $this->providerStations->findByRefs($provider, $refs);
        $changed = 0;
        foreach ($linked as $station) {
            $source = $found[$station->link->ref ?? ''] ?? null;
            if ($source !== null && $this->refresh($station, $source, $now)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @return bool whether anything changed
     */
    public function refresh(Station $station, ProviderStation $source, DateTimeImmutable $now): bool
    {
        if ($station->link === null || $station->link->keepMyDetails) {
            return false;
        }
        $updated = self::merged($station->data, $source);
        if ($updated == $station->data) {
            return false;
        }
        $this->stations->update($station->id, $updated, $now);

        return true;
    }

    /**
     * The station's details with the feed's where it has them.
     */
    public static function merged(StationData $data, ProviderStation $source): StationData
    {
        $feed = $source->data;
        $charging = array_values(array_filter(
            $data->grades,
            static fn ($grade): bool => $grade->family() === Fuel::Electricity,
        ));
        $position = $feed->hasPosition();

        return new StationData(
            name: $data->name,
            brand: $data->brand,
            address: $feed->address === null ? $data->address : mb_substr($feed->address, 0, 200),
            postcode: $feed->postcode ?? $data->postcode,
            country: $data->country,
            latitude: $position ? $feed->latitude : $data->latitude,
            longitude: $position ? $feed->longitude : $data->longitude,
            grades: $feed->grades === [] ? $data->grades : [...$feed->grades, ...$charging],
            openingHours: OpeningHours::text($feed->openingHours) ?? $data->openingHours,
            notes: $data->notes,
        );
    }
}
