<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Trip\TripData;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Trips (`trips`, spec.md §6 Trip). Vehicle queries are scoped to a vehicle
 * the caller has already resolved; `$onlyBy` narrows them to one driver's
 * trips for users who may not see everyone's (§7.22 *Access*).
 */
final readonly class TripRepository
{
    private const string TABLE = 'trips';
    public const int DISTANCE_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param int|null $onlyBy only this user's trips; null = everyone's
     * @return list<Trip> newest first: by date, then the latest logged
     */
    public function listForVehicle(int $vehicleId, ?int $onlyBy = null): array
    {
        $query = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER);
        self::narrow($query, $onlyBy);
        $rows = $query->orderBy('travelled_on', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Trips of several vehicles between two calendar dates.
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until exclusive; null = to the end
     * @param int|null $onlyBy only this user's trips; null = everyone's
     * @return list<Trip> oldest first: by date, then the order logged
     */
    public function listForVehiclesBetween(
        array $vehicleIds,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?int $onlyBy = null,
        bool $businessOnly = false,
    ): array {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        self::narrow($query, $onlyBy);
        if ($from !== null) {
            $query->andWhere('travelled_on >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $query->andWhere('travelled_on < :until')->setParameter('until', $until->format('Y-m-d'));
        }
        if ($businessOnly) {
            $query->andWhere('is_business = :business')->setParameter('business', true, ParameterType::BOOLEAN);
        }
        $rows = $query->orderBy('travelled_on')->addOrderBy('created_at')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * How many trips a vehicle has between two calendar dates.
     *
     * @param DateTimeImmutable $from inclusive
     * @param DateTimeImmutable $until exclusive
     * @param int|null $onlyBy only this user's trips; null = everyone's
     */
    public function countForVehicleBetween(
        int $vehicleId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?int $onlyBy = null,
    ): int {
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('vehicle_id = :vehicle', 'travelled_on >= :from', 'travelled_on < :until')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('until', $until->format('Y-m-d'));
        self::narrow($query, $onlyBy);
        $count = $query->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(int $vehicleId, int $id): ?Trip
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, TripData $data, DateTimeImmutable $now, ?int $createdBy): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), self::types() + ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, TripData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            self::types() + ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private static function narrow(QueryBuilder $query, ?int $onlyBy): void
    {
        if ($onlyBy !== null) {
            $query->andWhere('created_by = :by')->setParameter('by', $onlyBy, ParameterType::INTEGER);
        }
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'vehicle_id',
                'created_by',
                'travelled_on',
                'from_place',
                'to_place',
                'is_return',
                'distance_km',
                'odometer_start_km',
                'odometer_end_km',
                'is_business',
                'purpose',
                'passengers',
                'notes',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private static function dataColumns(TripData $data): array
    {
        return [
            'travelled_on' => $data->travelledOn->format('Y-m-d'),
            'from_place' => $data->fromPlace,
            'to_place' => $data->toPlace,
            'is_return' => $data->isReturn,
            'distance_km' => $data->distanceKm,
            'odometer_start_km' => $data->odometerStartKm,
            'odometer_end_km' => $data->odometerEndKm,
            'is_business' => $data->isBusiness,
            'purpose' => $data->purpose,
            'passengers' => $data->passengers,
            'notes' => $data->notes,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return [
            'is_return' => ParameterType::BOOLEAN,
            'is_business' => ParameterType::BOOLEAN,
            'passengers' => ParameterType::INTEGER,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Trip
    {
        $platform = $this->connection->getDatabasePlatform();

        return new Trip(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new TripData(
                travelledOn: Row::nullableDate($row, 'travelled_on')
                    ?? throw new UnexpectedValueException('Column "travelled_on" is null.'),
                fromPlace: Row::string($row, 'from_place'),
                toPlace: Row::string($row, 'to_place'),
                isReturn: Row::bool($row, 'is_return'),
                distanceKm: Row::decimal($row, 'distance_km', self::DISTANCE_SCALE),
                odometerStartKm: Row::nullableDecimal($row, 'odometer_start_km', self::DISTANCE_SCALE),
                odometerEndKm: Row::nullableDecimal($row, 'odometer_end_km', self::DISTANCE_SCALE),
                isBusiness: Row::bool($row, 'is_business'),
                purpose: Row::nullableString($row, 'purpose'),
                passengers: Row::int($row, 'passengers'),
                notes: Row::nullableString($row, 'notes'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
        );
    }
}
