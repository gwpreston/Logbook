<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use LogicException;

/**
 * The mileage series (`odometer_readings`). Every query is scoped to a
 * vehicle; callers pass a vehicle already checked to belong to the user.
 */
final readonly class OdometerReadingRepository
{
    private const string TABLE = 'odometer_readings';
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<OdometerReading> oldest first
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->orderBy('recorded_at')
            ->addOrderBy('reading_km')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The manual readings of several vehicles between two instants.
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive (UTC); null = from the start
     * @param DateTimeImmutable|null $until exclusive (UTC); null = to the end
     * @return list<OdometerReading> oldest first
     */
    public function listManualForVehiclesBetween(array $vehicleIds, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $platform = $this->connection->getDatabasePlatform();
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)', 'source = :manual')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->setParameter('manual', OdometerSource::Manual->value);
        if ($from !== null) {
            $query->andWhere('recorded_at >= :from')->setParameter('from', UtcDateTime::toDatabase($from, $platform));
        }
        if ($until !== null) {
            $query->andWhere('recorded_at < :until')->setParameter('until', UtcDateTime::toDatabase($until, $platform));
        }
        $rows = $query->orderBy('recorded_at')->addOrderBy('reading_km')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?OdometerReading
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByFuelEntry(int $vehicleId, int $fuelEntryId): ?OdometerReading
    {
        return $this->findByEntry($vehicleId, OdometerSource::Fuel, $fuelEntryId);
    }

    public function findByMaintenanceEntry(int $vehicleId, int $maintenanceEntryId): ?OdometerReading
    {
        return $this->findByEntry($vehicleId, OdometerSource::Maintenance, $maintenanceEntryId);
    }

    /**
     * The reading owned by a fill-up, maintenance entry or document.
     */
    public function findByEntry(int $vehicleId, OdometerSource $source, int $entryId): ?OdometerReading
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', self::entryColumn($source) . ' = :entry')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('entry', $entryId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param int|null $entryId the owning fill-up, maintenance entry or document (per $source); null for manual readings
     */
    public function insert(
        int $vehicleId,
        OdometerReadingData $data,
        OdometerSource $source,
        ?int $entryId,
        DateTimeImmutable $now,
    ): int {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $owner = $source === OdometerSource::Manual ? [] : [self::entryColumn($source) => $entryId];

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'source' => $source->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + $owner + $this->dataColumns($data), [
            'vehicle_id' => ParameterType::INTEGER,
            'fuel_entry_id' => ParameterType::INTEGER,
            'maintenance_entry_id' => ParameterType::INTEGER,
            'compliance_document_id' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, OdometerReadingData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + $this->dataColumns($data),
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
            ->select('id', 'vehicle_id', 'reading_km', 'recorded_at', 'source', 'note', 'fuel_entry_id')
            ->addSelect('maintenance_entry_id', 'compliance_document_id', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    private static function entryColumn(OdometerSource $source): string
    {
        return match ($source) {
            OdometerSource::Fuel => 'fuel_entry_id',
            OdometerSource::Maintenance => 'maintenance_entry_id',
            OdometerSource::Document => 'compliance_document_id',
            OdometerSource::Manual => throw new LogicException('Manual readings have no owning entry.'),
        };
    }

    /**
     * @return array<string, string|null>
     */
    private function dataColumns(OdometerReadingData $data): array
    {
        return [
            'reading_km' => $data->readingKm,
            'recorded_at' => UtcDateTime::toDatabase($data->recordedAt, $this->connection->getDatabasePlatform()),
            'note' => $data->note,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OdometerReading
    {
        $platform = $this->connection->getDatabasePlatform();

        return new OdometerReading(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            readingKm: Row::decimal($row, 'reading_km', self::KM_SCALE),
            recordedAt: UtcDateTime::fromDatabase($row['recorded_at'] ?? null, $platform),
            source: OdometerSource::from(Row::string($row, 'source')),
            note: Row::nullableString($row, 'note'),
            fuelEntryId: Row::nullableInt($row, 'fuel_entry_id'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            maintenanceEntryId: Row::nullableInt($row, 'maintenance_entry_id'),
            complianceDocumentId: Row::nullableInt($row, 'compliance_document_id'),
        );
    }
}
