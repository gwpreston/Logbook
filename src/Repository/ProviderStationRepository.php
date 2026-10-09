<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Domain\FuelPrices\FeedStation;
use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Geo\Haversine;

/**
 * The feed's copy of stations and their current prices
 * (`provider_stations`, `provider_prices`, spec.md §6 ProviderStation,
 * ProviderPrice, §7.34). Re-synced and never backed up. The sync writes a
 * page at a time; callers wrap each page in a transaction.
 */
final readonly class ProviderStationRepository
{
    private const string STATIONS = 'provider_stations';
    private const string PRICES = 'provider_prices';
    private const int POSITION_SCALE = 6;
    private const int PRICE_SCALE = 3;
    /** Rows per IN (...) list: well inside every engine's parameter limit. */
    private const int CHUNK = 500;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * Insert or update a page of stations. A permanently closed one is
     * marked removed; one listed again is restored (spec.md §7.34, #140).
     *
     * @param list<FeedStation> $stations
     * @return array{added: int, updated: int, removed: int}
     */
    public function upsertStations(string $provider, array $stations, DateTimeImmutable $now): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0];
        if ($stations === []) {
            return $counts;
        }
        $platform = $this->connection->getDatabasePlatform();
        $timestamp = UtcDateTime::toDatabase($now, $platform);
        $existing = $this->idsByRef($provider, array_map(static fn (FeedStation $s): string => $s->ref, $stations));

        foreach ($stations as $station) {
            $columns = self::columns($station) + ['updated_at' => $timestamp];
            $id = $existing[$station->ref] ?? null;
            if ($station->permanentlyClosed) {
                $counts['removed']++;
            }
            if ($id === null) {
                $this->connection->insert(self::STATIONS, $columns + [
                    'provider' => $provider,
                    'provider_ref' => $station->ref,
                    'removed_at' => $station->permanentlyClosed ? $timestamp : null,
                ], ['temporarily_closed' => ParameterType::BOOLEAN]);
                $existing[$station->ref] = (int) $this->connection->lastInsertId();
                $counts['added']++;
                continue;
            }
            $this->connection->update(
                self::STATIONS,
                $columns + ['removed_at' => $station->permanentlyClosed ? $timestamp : null],
                ['id' => $id],
                ['temporarily_closed' => ParameterType::BOOLEAN, 'id' => ParameterType::INTEGER],
            );
            $counts['updated']++;
        }

        return $counts;
    }

    /**
     * Mark every station of the provider not listed in a full sync as
     * removed (spec.md §7.34: only a full sync does this).
     *
     * @param array<string, true> $seen the refs the full sync listed
     */
    public function markMissingRemoved(string $provider, array $seen, DateTimeImmutable $now): int
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'provider_ref')
            ->from(self::STATIONS)
            ->where('provider = :provider', 'removed_at IS NULL')
            ->setParameter('provider', $provider)
            ->fetchAllAssociative();
        $missing = [];
        foreach ($rows as $row) {
            if (!isset($seen[Row::string($row, 'provider_ref')])) {
                $missing[] = Row::int($row, 'id');
            }
        }
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            $this->connection->createQueryBuilder()
                ->update(self::STATIONS)
                ->set('removed_at', ':now')
                ->where('id IN (:ids)')
                ->setParameter('now', $timestamp)
                ->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                ->executeStatement();
        }

        return count($missing);
    }

    /**
     * Insert or update a page of prices. Prices for a station not stored
     * (an incremental page ahead of its station) are skipped.
     *
     * @param list<FeedPrice> $prices
     * @return array{saved: int, changed: int, unknown: int}
     */
    public function upsertPrices(string $provider, array $prices, DateTimeImmutable $now): array
    {
        $counts = ['saved' => 0, 'changed' => 0, 'unknown' => 0];
        if ($prices === []) {
            return $counts;
        }
        $platform = $this->connection->getDatabasePlatform();
        $timestamp = UtcDateTime::toDatabase($now, $platform);
        $ids = $this->idsByRef($provider, array_map(static fn (FeedPrice $p): string => $p->ref, $prices));
        $current = $this->rawPrices(array_values($ids));

        foreach ($prices as $price) {
            $id = $ids[$price->ref] ?? null;
            if ($id === null) {
                $counts['unknown']++;
                continue;
            }
            // A time ahead of the sync (a clock or zone slip at the provider) is
            // taken as the sync's own, so the price is never out of reach.
            if ($price->reportedAt > $now) {
                $price = new FeedPrice($price->ref, $price->grade, $price->price, $now);
            }
            $reported = UtcDateTime::toDatabase($price->reportedAt, $platform);
            $before = $current[$id][$price->grade->value] ?? null;
            if ($before === null) {
                $this->connection->insert(self::PRICES, [
                    'provider_station_id' => $id,
                    'grade' => $price->grade->value,
                    'price' => $price->price,
                    'reported_at' => $reported,
                    'synced_at' => $timestamp,
                ], ['provider_station_id' => ParameterType::INTEGER]);
                $current[$id][$price->grade->value] = ['price' => $price->price, 'reported_at' => $price->reportedAt];
                $counts['changed']++;
            } else {
                // An older report than the one stored never replaces it.
                if ($price->reportedAt < $before['reported_at']) {
                    continue;
                }
                $this->connection->update(self::PRICES, [
                    'price' => $price->price,
                    'reported_at' => $reported,
                    'synced_at' => $timestamp,
                ], ['provider_station_id' => $id, 'grade' => $price->grade->value], [
                    'provider_station_id' => ParameterType::INTEGER,
                ]);
                if ($price->reportedAt != $before['reported_at'] || $price->price !== $before['price']) {
                    $counts['changed']++;
                }
                $current[$id][$price->grade->value] = ['price' => $price->price, 'reported_at' => $price->reportedAt];
            }
            $counts['saved']++;
        }

        return $counts;
    }

    /**
     * After a full sync: prices of the provider's stations the feed no
     * longer lists (not synced since it started) are dropped.
     */
    public function dropPricesNotSyncedSince(string $provider, DateTimeImmutable $since): int
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::STATIONS)
            ->where('provider = :provider')
            ->setParameter('provider', $provider)
            ->fetchFirstColumn();
        $dropped = 0;
        $before = UtcDateTime::toDatabase($since, $this->connection->getDatabasePlatform());
        foreach (array_chunk(self::ints($ids), self::CHUNK) as $chunk) {
            $dropped += (int) $this->connection->createQueryBuilder()
                ->delete(self::PRICES)
                ->where('provider_station_id IN (:ids)', 'synced_at < :before')
                ->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                ->setParameter('before', $before)
                ->executeStatement();
        }

        return $dropped;
    }

    public function count(string $provider, bool $includeRemoved = false): int
    {
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::STATIONS)
            ->where('provider = :provider')
            ->setParameter('provider', $provider);
        if (!$includeRemoved) {
            $query->andWhere('removed_at IS NULL');
        }

        $count = $query->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countPrices(string $provider): int
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::PRICES, 'p')
            ->innerJoin('p', self::STATIONS, 's', 's.id = p.provider_station_id')
            ->where('s.provider = :provider', 's.removed_at IS NULL')
            ->setParameter('provider', $provider)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * When prices were last saved for the provider.
     */
    public function lastSynced(string $provider): ?DateTimeImmutable
    {
        $value = $this->connection->createQueryBuilder()
            ->select('MAX(p.synced_at)')
            ->from(self::PRICES, 'p')
            ->innerJoin('p', self::STATIONS, 's', 's.id = p.provider_station_id')
            ->where('s.provider = :provider')
            ->setParameter('provider', $provider)
            ->fetchOne();

        return is_string($value) && $value !== ''
            ? UtcDateTime::fromDatabase($value, $this->connection->getDatabasePlatform())
            : null;
    }

    public function findByRef(string $provider, string $ref): ?ProviderStation
    {
        return $this->findByRefs($provider, [$ref])[$ref] ?? null;
    }

    /**
     * @param list<string> $refs
     * @return array<string, ProviderStation> by ref
     */
    public function findByRefs(string $provider, array $refs): array
    {
        if (!$this->reads->isActive()) {
            return $this->readByRefs($provider, $refs);
        }
        // Remembered per ref for the page (spec.md §8 *Page budgets*).
        $keys = array_map(static fn (string $ref): string => $provider . '|' . $ref, $refs);
        $this->reads->prime(self::STATIONS, $keys, function (array $missing) use ($provider): array {
            $byKey = [];
            $refs = array_map(static fn (string $key): string => substr($key, strlen($provider) + 1), $missing);
            foreach ($this->readByRefs($provider, $refs) as $ref => $station) {
                $byKey[$provider . '|' . $ref] = $station;
            }

            return $byKey;
        }, null);
        $found = [];
        foreach ($refs as $ref) {
            $station = $this->reads->remember(
                self::STATIONS,
                $provider . '|' . $ref,
                fn (): ?ProviderStation => $this->readByRefs($provider, [$ref])[$ref] ?? null,
            );
            if ($station !== null) {
                $found[$ref] = $station;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $refs
     * @return array<string, ProviderStation> by ref
     */
    private function readByRefs(string $provider, array $refs): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($refs)), self::CHUNK) as $chunk) {
            $rows = $this->select()
                ->where('provider = :provider', 'provider_ref IN (:refs)')
                ->setParameter('provider', $provider)
                ->setParameter('refs', $chunk, ArrayParameterType::STRING)
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $station = $this->hydrate($row);
                $found[$station->ref()] = $station;
            }
        }

        return $found;
    }

    /**
     * Stations of the provider with a position within $km of a point: a
     * bounding box in SQL, then haversine (spec.md §7.34).
     *
     * @return list<array{station: ProviderStation, km: float}> nearest first
     */
    public function nearby(string $provider, float $latitude, float $longitude, float $km, bool $includeRemoved = false): array
    {
        // Every vehicle on a page searches around the same place (spec.md §8 *Page budgets*).
        $key = implode('|', [$provider, $latitude, $longitude, $km, $includeRemoved ? 1 : 0]);

        return $this->reads->remember(
            self::STATIONS,
            'nearby|' . $key,
            fn (): array => $this->readNearby($provider, $latitude, $longitude, $km, $includeRemoved),
        );
    }

    /**
     * @return list<array{station: ProviderStation, km: float}> nearest first
     */
    private function readNearby(string $provider, float $latitude, float $longitude, float $km, bool $includeRemoved): array
    {
        [$dLat, $dLon] = Haversine::box($latitude, $km);
        $query = $this->select()
            ->where('provider = :provider', 'latitude IS NOT NULL', 'longitude IS NOT NULL')
            ->andWhere('latitude BETWEEN :south AND :north')
            ->setParameter('provider', $provider)
            ->setParameter('south', self::coordinate($latitude - $dLat))
            ->setParameter('north', self::coordinate($latitude + $dLat));
        if (!$includeRemoved) {
            $query->andWhere('removed_at IS NULL');
        }
        $west = $longitude - $dLon;
        $east = $longitude + $dLon;
        if ($dLon < 180.0 && $west >= -180.0 && $east <= 180.0) {
            $query->andWhere('longitude BETWEEN :west AND :east')
                ->setParameter('west', self::coordinate($west))
                ->setParameter('east', self::coordinate($east));
        }

        $found = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $station = $this->hydrate($row);
            $distance = Haversine::km(
                $latitude,
                $longitude,
                (float) $station->data->latitude,
                (float) $station->data->longitude,
            );
            if ($distance <= $km) {
                $found[] = ['station' => $station, 'km' => $distance];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a['km'] <=> $b['km']);

        return $found;
    }

    /**
     * Stations of the provider at a postcode (spaces and case ignored).
     *
     * @return list<ProviderStation>
     */
    public function byPostcode(string $provider, string $postcode): array
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $postcode));
        if ($compact === '') {
            return [];
        }
        // Stored as the feed gives it ("SL6 0AA"); compared without spaces in PHP.
        $outward = substr($compact, 0, max(2, strlen($compact) - 3));
        $rows = $this->select()
            ->where('provider = :provider', 'removed_at IS NULL')
            ->andWhere('UPPER(postcode) LIKE :outward')
            ->setParameter('provider', $provider)
            ->setParameter('outward', $outward . '%')
            ->fetchAllAssociative();

        return array_values(array_filter(
            array_map($this->hydrate(...), $rows),
            static fn (ProviderStation $s): bool
                => strtoupper((string) preg_replace('/\s+/', '', $s->data->postcode ?? '')) === $compact,
        ));
    }

    /**
     * Stations of the provider whose name or brand contains the text
     * (case-insensitive), for linking a station without a position.
     *
     * @return list<ProviderStation>
     */
    public function searchByName(string $provider, string $text, int $limit = 50): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $like = '%' . addcslashes(mb_strtolower($text), '%_\\') . '%';

        $rows = $this->select()
            ->where('provider = :provider', 'removed_at IS NULL')
            ->andWhere('(LOWER(name) LIKE :text OR LOWER(brand) LIKE :text)')
            ->setParameter('provider', $provider)
            ->setParameter('text', $like)
            ->orderBy('name')
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Current prices of stations, by station id and grade.
     *
     * @param list<int> $stationIds
     * @return array<int, array<string, ListedPrice>>
     */
    public function prices(array $stationIds, ?FuelGrade $grade = null): array
    {
        if (!$this->reads->isActive()) {
            return $this->readPrices($stationIds, $grade);
        }
        // Every grade of a station is remembered for the page; a grade is picked here.
        $this->reads->prime(
            self::PRICES,
            $stationIds,
            fn (array $ids): array => $this->readPrices($ids, null),
            [],
        );
        $prices = [];
        foreach (array_unique($stationIds) as $id) {
            $all = $this->reads->remember(self::PRICES, $id, fn (): array => $this->readPrices([$id], null)[$id] ?? []);
            $kept = $grade === null ? $all : array_intersect_key($all, [$grade->value => true]);
            if ($kept !== []) {
                $prices[$id] = $kept;
            }
        }

        return $prices;
    }

    /**
     * @param list<int> $stationIds
     * @return array<int, array<string, ListedPrice>>
     */
    private function readPrices(array $stationIds, ?FuelGrade $grade): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $prices = [];
        foreach (array_chunk(array_values(array_unique($stationIds)), self::CHUNK) as $chunk) {
            $query = $this->connection->createQueryBuilder()
                ->select('provider_station_id', 'grade', 'price', 'reported_at', 'synced_at')
                ->from(self::PRICES)
                ->where('provider_station_id IN (:ids)')
                ->setParameter('ids', $chunk, ArrayParameterType::INTEGER);
            if ($grade !== null) {
                $query->andWhere('grade = :grade')->setParameter('grade', $grade->value);
            }
            foreach ($query->fetchAllAssociative() as $row) {
                $code = FuelGrade::tryFrom(Row::string($row, 'grade'));
                if ($code === null) {
                    continue;
                }
                $prices[Row::int($row, 'provider_station_id')][$code->value] = new ListedPrice(
                    $code,
                    Row::decimal($row, 'price', self::PRICE_SCALE),
                    UtcDateTime::fromDatabase($row['reported_at'] ?? null, $platform),
                    UtcDateTime::fromDatabase($row['synced_at'] ?? null, $platform),
                );
            }
        }

        return $prices;
    }

    /**
     * Remove every station and price of a provider (tests and the demo).
     */
    public function clear(string $provider): void
    {
        $this->connection->delete(self::STATIONS, ['provider' => $provider]);
    }

    /**
     * @param list<string> $refs
     * @return array<string, int> row ids by ref
     */
    private function idsByRef(string $provider, array $refs): array
    {
        $ids = [];
        foreach (array_chunk(array_values(array_unique($refs)), self::CHUNK) as $chunk) {
            $rows = $this->connection->createQueryBuilder()
                ->select('id', 'provider_ref')
                ->from(self::STATIONS)
                ->where('provider = :provider', 'provider_ref IN (:refs)')
                ->setParameter('provider', $provider)
                ->setParameter('refs', $chunk, ArrayParameterType::STRING)
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $ids[Row::string($row, 'provider_ref')] = Row::int($row, 'id');
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, array{price: string, reported_at: DateTimeImmutable}>>
     */
    private function rawPrices(array $ids): array
    {
        $current = [];
        foreach ($this->prices($ids) as $id => $grades) {
            foreach ($grades as $code => $listed) {
                $current[$id][$code] = ['price' => $listed->price, 'reported_at' => $listed->reportedAt];
            }
        }

        return $current;
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'provider',
                'provider_ref',
                'name',
                'brand',
                'address',
                'postcode',
                'latitude',
                'longitude',
                'opening_hours',
                'amenities',
                'grades',
                'temporarily_closed',
                'updated_at',
                'removed_at',
            )
            ->from(self::STATIONS);
    }

    /**
     * @return array<string, mixed>
     */
    private static function columns(FeedStation $station): array
    {
        return [
            'name' => $station->name,
            'brand' => $station->brand,
            'address' => $station->address,
            'postcode' => $station->postcode,
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'opening_hours' => $station->openingHours === null ? null : json_encode($station->openingHours, JSON_THROW_ON_ERROR),
            'amenities' => $station->amenities === [] ? null : json_encode($station->amenities, JSON_THROW_ON_ERROR),
            'grades' => $station->grades === []
                ? null
                : json_encode(array_map(static fn (FuelGrade $g): string => $g->value, $station->grades), JSON_THROW_ON_ERROR),
            'temporarily_closed' => $station->temporarilyClosed,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProviderStation
    {
        $platform = $this->connection->getDatabasePlatform();
        $grades = [];
        foreach (self::jsonList($row, 'grades') as $code) {
            $grade = is_string($code) ? FuelGrade::tryFrom($code) : null;
            if ($grade !== null) {
                $grades[] = $grade;
            }
        }
        $hours = json_decode(Row::nullableString($row, 'opening_hours') ?? 'null', true);
        $latitude = Row::nullableDecimal($row, 'latitude', self::POSITION_SCALE);
        $longitude = Row::nullableDecimal($row, 'longitude', self::POSITION_SCALE);
        if ($latitude === null || $longitude === null) {
            $latitude = $longitude = null;
        }
        $removed = $row['removed_at'] ?? null;

        return new ProviderStation(
            id: Row::int($row, 'id'),
            provider: Row::string($row, 'provider'),
            data: new FeedStation(
                ref: Row::string($row, 'provider_ref'),
                name: Row::string($row, 'name'),
                brand: Row::nullableString($row, 'brand'),
                address: Row::nullableString($row, 'address'),
                postcode: Row::nullableString($row, 'postcode'),
                latitude: $latitude,
                longitude: $longitude,
                openingHours: is_array($hours) && !array_is_list($hours) ? self::keyed($hours) : null,
                amenities: array_values(array_filter(self::jsonList($row, 'amenities'), 'is_string')),
                grades: $grades,
                temporarilyClosed: Row::bool($row, 'temporarily_closed'),
            ),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            removedAt: $removed === null ? null : UtcDateTime::fromDatabase($removed, $platform),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private static function jsonList(array $row, string $column): array
    {
        $value = json_decode(Row::nullableString($row, $column) ?? '[]', true);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function keyed(array $value): array
    {
        $keyed = [];
        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }

    /**
     * @param array<int, mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $values));
    }

    private static function coordinate(float $value): string
    {
        return number_format($value, self::POSITION_SCALE, '.', '');
    }
}
