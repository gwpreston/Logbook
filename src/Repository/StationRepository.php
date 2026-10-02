<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Geo\Haversine;

/**
 * Fuel stations (`stations`) and each user's favourites
 * (`station_favourites`), spec.md §6 Station, §7.33. Stations are shared by
 * the whole install; favourites are scoped to their user.
 *
 * Names are matched by StationName::normalise() in PHP, never in SQL, so
 * every engine and collation agrees (an install holds hundreds of
 * stations, not millions).
 */
final readonly class StationRepository
{
    private const string TABLE = 'stations';
    private const string FAVOURITES = 'station_favourites';
    public const int POSITION_SCALE = 6;
    /** How far merged_into is followed before giving up (a cycle is never written). */
    private const int MAX_MERGE_HOPS = 16;

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?Station
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The station an id stands for now: itself, or the one it was merged into.
     */
    public function resolve(int $id): ?Station
    {
        $station = $this->find($id);
        for ($hops = 0; $station !== null && $station->mergedInto !== null && $hops < self::MAX_MERGE_HOPS; $hops++) {
            $station = $this->find($station->mergedInto);
        }

        return $station?->isMerged() === true ? null : $station;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Station> keyed by id (merged ones included)
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->select()
            ->where('id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)), ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $stations = [];
        foreach ($rows as $row) {
            $station = $this->hydrate($row);
            $stations[$station->id] = $station;
        }

        return $stations;
    }

    /**
     * Every station not merged into another, by name.
     *
     * @return list<Station>
     */
    public function listActive(): array
    {
        $rows = $this->select()
            ->where('merged_into IS NULL')
            ->orderBy('name')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The unmerged station with this normalised name (the oldest, should
     * there be two), or none.
     */
    public function findByName(string $name): ?Station
    {
        $key = StationName::normalise($name);
        if ($key === '') {
            return null;
        }
        $found = null;
        foreach ($this->listActive() as $station) {
            if (StationName::normalise($station->data->name) === $key && ($found === null || $station->id < $found->id)) {
                $found = $station;
            }
        }

        return $found;
    }

    /**
     * Unmerged stations whose name, brand or postcode contains the text,
     * ignoring case and spacing (compared in PHP).
     *
     * @return list<Station> by name
     */
    public function search(string $text): array
    {
        $needle = StationName::normalise($text);
        if ($needle === '') {
            return $this->listActive();
        }
        $compact = str_replace(' ', '', $needle);

        return array_values(array_filter(
            $this->listActive(),
            static function (Station $station) use ($needle, $compact): bool {
                $data = $station->data;
                foreach ([$data->name, $data->brand ?? ''] as $field) {
                    if (str_contains(StationName::normalise($field), $needle)) {
                        return true;
                    }
                }

                return $data->postcode !== null
                    && str_contains(str_replace(' ', '', StationName::normalise($data->postcode)), $compact);
            },
        ));
    }

    /**
     * Unmerged stations with a position within $km of a point: a bounding
     * box in SQL, then the exact distance.
     *
     * @return list<array{station: Station, km: float}> nearest first
     */
    public function nearby(float $latitude, float $longitude, float $km): array
    {
        [$dLat, $dLon] = Haversine::box($latitude, $km);
        $query = $this->select()
            ->where('merged_into IS NULL', 'latitude IS NOT NULL', 'longitude IS NOT NULL')
            ->andWhere('latitude BETWEEN :south AND :north')
            ->setParameter('south', self::coordinate($latitude - $dLat))
            ->setParameter('north', self::coordinate($latitude + $dLat));
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

    public function insert(StationData $data, ?int $createdBy, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TABLE, [
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, StationData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Merge $from into $into (spec.md §7.33 *Merge*): its fill-ups and
     * favourites move, stations already merged into it follow, and it
     * points at $into. The caller runs it in a transaction with the update
     * of $into's details.
     */
    public function merge(int $from, int $into, DateTimeImmutable $now): void
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->update(
            'fuel_entries',
            ['station_id' => $into],
            ['station_id' => $from],
            ['station_id' => ParameterType::INTEGER],
        );

        // A user who favoured both keeps one favourite.
        $both = $this->connection->createQueryBuilder()
            ->select('user_id')
            ->from(self::FAVOURITES)
            ->where('station_id = :into')
            ->setParameter('into', $into, ParameterType::INTEGER)
            ->fetchFirstColumn();
        if ($both !== []) {
            $this->connection->createQueryBuilder()
                ->delete(self::FAVOURITES)
                ->where('station_id = :from', 'user_id IN (:users)')
                ->setParameter('from', $from, ParameterType::INTEGER)
                ->setParameter('users', self::ids($both), ArrayParameterType::INTEGER)
                ->executeStatement();
        }
        $this->connection->update(
            self::FAVOURITES,
            ['station_id' => $into],
            ['station_id' => $from],
            ['station_id' => ParameterType::INTEGER],
        );

        $this->connection->update(
            self::TABLE,
            ['merged_into' => $into, 'updated_at' => $timestamp],
            ['merged_into' => $from],
            ['merged_into' => ParameterType::INTEGER],
        );
        $this->connection->update(
            self::TABLE,
            ['merged_into' => $into, 'updated_at' => $timestamp],
            ['id' => $from],
            ['merged_into' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<int> the station ids the user has favoured
     */
    public function favouriteIds(int $userId): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('station_id')
            ->from(self::FAVOURITES)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('station_id')
            ->fetchFirstColumn();

        return self::ids($ids);
    }

    public function setFavourite(int $userId, int $stationId, bool $favourite, DateTimeImmutable $now): void
    {
        $this->connection->delete(
            self::FAVOURITES,
            ['user_id' => $userId, 'station_id' => $stationId],
            ['user_id' => ParameterType::INTEGER, 'station_id' => ParameterType::INTEGER],
        );
        if ($favourite) {
            $this->connection->insert(self::FAVOURITES, [
                'user_id' => $userId,
                'station_id' => $stationId,
                'created_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
            ], ['user_id' => ParameterType::INTEGER, 'station_id' => ParameterType::INTEGER]);
        }
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'name',
                'brand',
                'address',
                'postcode',
                'country',
                'latitude',
                'longitude',
                'grades',
                'opening_hours',
                'notes',
                'created_by',
                'merged_into',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, string|null>
     */
    private static function dataColumns(StationData $data): array
    {
        return [
            'name' => $data->name,
            'brand' => $data->brand,
            'address' => $data->address,
            'postcode' => $data->postcode,
            'country' => $data->country,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'grades' => $data->grades === []
                ? null
                : json_encode(
                    array_map(static fn (FuelGrade $grade): string => $grade->value, $data->grades),
                    JSON_THROW_ON_ERROR,
                ),
            'opening_hours' => $data->openingHours,
            'notes' => $data->notes,
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $values));
    }

    private static function coordinate(float $value): string
    {
        return number_format($value, self::POSITION_SCALE, '.', '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Station
    {
        $platform = $this->connection->getDatabasePlatform();
        $codes = json_decode(Row::nullableString($row, 'grades') ?? '[]', true);
        $grades = [];
        foreach (is_array($codes) ? $codes : [] as $code) {
            // An unknown code (a grade from a later version) is skipped.
            $grade = is_string($code) ? FuelGrade::tryFrom($code) : null;
            if ($grade !== null) {
                $grades[] = $grade;
            }
        }
        $latitude = Row::nullableDecimal($row, 'latitude', self::POSITION_SCALE);
        $longitude = Row::nullableDecimal($row, 'longitude', self::POSITION_SCALE);
        if ($latitude === null || $longitude === null) {
            $latitude = $longitude = null;
        }

        return new Station(
            id: Row::int($row, 'id'),
            data: new StationData(
                name: Row::string($row, 'name'),
                brand: Row::nullableString($row, 'brand'),
                address: Row::nullableString($row, 'address'),
                postcode: Row::nullableString($row, 'postcode'),
                country: Row::nullableString($row, 'country'),
                latitude: $latitude,
                longitude: $longitude,
                grades: $grades,
                openingHours: Row::nullableString($row, 'opening_hours'),
                notes: Row::nullableString($row, 'notes'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
            mergedInto: Row::nullableInt($row, 'merged_into'),
        );
    }
}
