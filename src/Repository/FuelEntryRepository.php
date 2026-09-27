<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
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

    public function __construct(private Connection $connection)
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

    public function find(int $vehicleId, int $id): ?FuelEntry
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, FuelEntryData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
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
                'volume',
                'price_per_unit',
                'total_cost',
                'is_partial',
                'is_missed_previous',
                'station',
                'notes',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function dataColumns(FuelEntryData $data): array
    {
        return [
            'filled_at' => UtcDateTime::toDatabase($data->filledAt, $this->connection->getDatabasePlatform()),
            'odometer_km' => $data->odometerKm,
            'fuel' => $data->fuel->value,
            'volume' => $data->volume,
            'price_per_unit' => $data->pricePerUnit,
            'total_cost' => $data->totalCost,
            'is_partial' => $data->isPartial,
            'is_missed_previous' => $data->isMissedPrevious,
            'station' => $data->station,
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

        return new FuelEntry(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new FuelEntryData(
                filledAt: UtcDateTime::fromDatabase($row['filled_at'] ?? null, $platform),
                odometerKm: Row::decimal($row, 'odometer_km', self::QUANTITY_SCALE),
                fuel: Fuel::from(Row::string($row, 'fuel')),
                volume: Row::decimal($row, 'volume', self::QUANTITY_SCALE),
                pricePerUnit: Row::decimal($row, 'price_per_unit', self::PRICE_SCALE),
                totalCost: Row::decimal($row, 'total_cost', self::MONEY_SCALE),
                isPartial: Row::bool($row, 'is_partial'),
                isMissedPrevious: Row::bool($row, 'is_missed_previous'),
                station: Row::nullableString($row, 'station'),
                notes: Row::nullableString($row, 'notes'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
