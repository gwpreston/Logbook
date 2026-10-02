<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Fill-ups (`fuel_entries`). Every query is scoped to a vehicle; callers pass
 * a vehicle already checked to belong to the user.
 */
final readonly class FuelEntryRepository
{
    private const string TABLE = 'fuel_entries';
    public const int QUANTITY_SCALE = 3;
    public const int PRICE_SCALE = 6;
    public const int MONEY_SCALE = 3;
    public const int CONSUMPTION_SCALE = 6;

    public function __construct(private Connection $connection, private StoredGrade $grades)
    {
    }

    /**
     * @return list<FuelEntry> in the order they happened (time, then odometer)
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->orderBy('filled_at')
            ->addOrderBy('odometer_km')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The fill-ups of several vehicles between two instants (the History
     * pages bound a year by its local start and end in UTC; spec.md §7.16).
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive (UTC); null = from the start
     * @param DateTimeImmutable|null $until exclusive (UTC); null = to the end
     * @return list<FuelEntry> in the order they happened
     */
    public function listForVehiclesBetween(array $vehicleIds, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $platform = $this->connection->getDatabasePlatform();
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($from !== null) {
            $query->andWhere('filled_at >= :from')->setParameter('from', UtcDateTime::toDatabase($from, $platform));
        }
        if ($until !== null) {
            $query->andWhere('filled_at < :until')->setParameter('until', UtcDateTime::toDatabase($until, $platform));
        }
        $rows = $query->orderBy('filled_at')->addOrderBy('odometer_km')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The fill-ups of several vehicles linked to a station (Phase 30.1,
     * spec.md §7.33): to any station, or to the given ones.
     *
     * @param list<int> $vehicleIds
     * @param list<int>|null $stationIds null = any station
     * @return list<FuelEntry> in the order they happened
     */
    public function listLinked(array $vehicleIds, ?array $stationIds = null): array
    {
        if ($vehicleIds === [] || $stationIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)', 'station_id IS NOT NULL')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($stationIds !== null) {
            $query->andWhere('station_id IN (:stations)')->setParameter('stations', $stationIds, ArrayParameterType::INTEGER);
        }
        $rows = $query->orderBy('filled_at')->addOrderBy('odometer_km')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?FuelEntry
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, FuelEntryData $data, DateTimeImmutable $now, ?int $createdBy = null): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => $createdBy,
        ] + $this->dataColumns($data), ['vehicle_id' => ParameterType::INTEGER] + self::types());

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, FuelEntryData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + $this->dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER] + self::types(),
        );
    }

    /**
     * Keep the station text of linked fill-ups the station's name (Phase
     * 30.1): after a rename or a merge. Leaves updated_at alone.
     */
    public function renameStation(int $stationId, string $name): void
    {
        $this->connection->update(
            self::TABLE,
            ['station' => $name],
            ['station_id' => $stationId],
            ['station_id' => ParameterType::INTEGER],
        );
    }

    /**
     * Record (or, with null, clear) the confirmed economy of a fill-up
     * (spec.md §7.3). Leaves updated_at alone: the fill-up itself is unchanged.
     */
    public function setEconomyConfirmed(int $vehicleId, int $id, ?string $consumption): void
    {
        $this->connection->update(
            self::TABLE,
            ['economy_confirmed' => $consumption],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
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

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'vehicle_id',
                'filled_at',
                'odometer_km',
                'fuel',
                'grade',
                'volume',
                'price_per_unit',
                'total_cost',
                'is_partial',
                'is_missed_previous',
                'station',
                'station_id',
                // The linked station's name, shown in place of the text as typed (spec.md §6
                // FuelEntry): fill-ups linked by the upgrade keep their original text.
                '(SELECT s.name FROM stations s WHERE s.id = fuel_entries.station_id) AS station_name',
                'notes',
                'economy_confirmed',
                'created_at',
                'updated_at',
                'created_by',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function dataColumns(FuelEntryData $data): array
    {
        return [
            'filled_at' => UtcDateTime::toDatabase($data->filledAt, $this->connection->getDatabasePlatform()),
            'odometer_km' => $data->odometerKm,
            'fuel' => $data->fuel->value,
            'grade' => $data->grade?->value,
            'volume' => $data->volume,
            'price_per_unit' => $data->pricePerUnit,
            'total_cost' => $data->totalCost,
            'is_partial' => $data->isPartial,
            'is_missed_previous' => $data->isMissedPrevious,
            'station' => $data->station,
            'station_id' => $data->stationId,
            'notes' => $data->notes,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return ['is_partial' => ParameterType::BOOLEAN, 'is_missed_previous' => ParameterType::BOOLEAN];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FuelEntry
    {
        $platform = $this->connection->getDatabasePlatform();
        $id = Row::int($row, 'id');
        $fuel = Fuel::from(Row::string($row, 'fuel'));

        return new FuelEntry(
            id: $id,
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new FuelEntryData(
                filledAt: UtcDateTime::fromDatabase($row['filled_at'] ?? null, $platform),
                odometerKm: Row::decimal($row, 'odometer_km', self::QUANTITY_SCALE),
                fuel: $fuel,
                volume: Row::decimal($row, 'volume', self::QUANTITY_SCALE),
                pricePerUnit: Row::decimal($row, 'price_per_unit', self::PRICE_SCALE),
                totalCost: Row::decimal($row, 'total_cost', self::MONEY_SCALE),
                isPartial: Row::bool($row, 'is_partial'),
                isMissedPrevious: Row::bool($row, 'is_missed_previous'),
                station: Row::nullableString($row, 'station_name') ?? Row::nullableString($row, 'station'),
                notes: Row::nullableString($row, 'notes'),
                grade: $this->grades->read(Row::nullableString($row, 'grade'), $fuel, self::TABLE, $id),
                stationId: Row::nullableInt($row, 'station_id'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
            economyConfirmed: Row::nullableDecimal($row, 'economy_confirmed', self::CONSUMPTION_SCALE),
        );
    }
}
