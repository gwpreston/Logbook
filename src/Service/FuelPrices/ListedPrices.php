<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\Station\Station;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Listed prices for Logbook stations (spec.md §7.34): what a linked
 * station lists now, and its listed price history by day. Nothing while
 * no provider is enabled, or for a station linked to another provider.
 */
final readonly class ListedPrices
{
    public function __construct(
        private FuelPriceConfig $config,
        private ProviderStationRepository $providerStations,
        private ListedPriceRepository $history,
        private ClockInterface $clock,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    public function provider(): ?PriceProvider
    {
        return $this->config->provider();
    }

    public function forStation(Station $station): ?StationPrices
    {
        return $this->forStations([$station])[$station->id] ?? null;
    }

    /**
     * @param list<Station> $stations
     * @return array<int, StationPrices> by station id, linked ones only
     */
    public function forStations(array $stations): array
    {
        $provider = $this->config->provider();
        if ($provider === null) {
            return [];
        }
        $linked = array_values(array_filter(
            $stations,
            static fn (Station $s): bool => $s->link !== null && $s->link->provider === $provider->code(),
        ));
        if ($linked === []) {
            return [];
        }
        $sources = $this->providerStations->findByRefs(
            $provider->code(),
            array_map(static fn (Station $s): string => $s->link->ref ?? '', $linked),
        );
        $prices = $this->providerStations->prices(array_values(array_map(static fn ($s): int => $s->id, $sources)));
        $now = $this->clock->now();
        $found = [];
        foreach ($linked as $station) {
            $source = $sources[$station->link->ref ?? ''] ?? null;
            if ($source !== null) {
                $found[$station->id] = new StationPrices($provider, $source, self::inOrder($prices[$source->id] ?? []), $now);
            }
        }

        return $found;
    }

    /**
     * Prices in the pickers' grade order (E10 before E5, petrol before diesel).
     *
     * @param array<string, ListedPrice> $prices
     * @return array<string, ListedPrice>
     */
    private static function inOrder(array $prices): array
    {
        $ordered = [];
        foreach (FuelGrade::cases() as $grade) {
            if (isset($prices[$grade->value])) {
                $ordered[$grade->value] = $prices[$grade->value];
            }
        }

        return $ordered;
    }

    /**
     * The listed price history of a linked station by grade and day (in
     * the viewer's time zone), oldest first.
     *
     * @return array<string, list<DailyPrice>> by grade code
     */
    public function daily(Station $station, DateTimeZone $zone, ?DateTimeImmutable $since = null): array
    {
        $provider = $this->config->provider();
        if ($provider === null || $station->link === null || $station->link->provider !== $provider->code()) {
            return [];
        }
        $days = [];
        foreach ($this->history->changes($provider->code(), $station->link->ref, null, $since) as $change) {
            $day = $change->reportedAt->setTimezone($zone)->format('Y-m-d');
            $entry = $days[$change->grade->value][$day] ?? null;
            $days[$change->grade->value][$day] = $entry === null
                ? ['low' => $change->price, 'high' => $change->price, 'close' => $change->price]
                : [
                    'low' => Decimal::compare($change->price, $entry['low']) < 0 ? $change->price : $entry['low'],
                    'high' => Decimal::compare($change->price, $entry['high']) > 0 ? $change->price : $entry['high'],
                    'close' => $change->price,
                ];
        }
        $series = [];
        foreach ($days as $grade => $byDay) {
            foreach ($byDay as $day => $entry) {
                $series[$grade][] = new DailyPrice(
                    new DateTimeImmutable($day, $zone),
                    $entry['low'],
                    $entry['high'],
                    $entry['close'],
                );
            }
        }

        return $series;
    }

    /**
     * The price in effect at a moment from a station's history, reported
     * no more than 48 hours before it (spec.md §7.34, #144).
     */
    public function priceAt(Station $station, FuelGrade $grade, DateTimeImmutable $at): ?ListedPrice
    {
        $provider = $this->config->provider();
        if ($provider === null || $station->link === null || $station->link->provider !== $provider->code()) {
            return null;
        }
        $change = $this->history->priceAt($provider->code(), $station->link->ref, $grade, $at);
        if ($change !== null && ListedPrice::freshAt($change->reportedAt, $at)) {
            return new ListedPrice($grade, $change->price, $change->reportedAt);
        }

        // A station tracked only since this fill-up has no history until the
        // next sync: its current price counts when it was already in effect.
        $current = $this->forStation($station)?->price($grade->value);
        if ($current !== null && $current->reportedAt <= $at && ListedPrice::freshAt($current->reportedAt, $at)) {
            return $current;
        }

        return null;
    }
}
