<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Service history (`maintenance_entries`). Every query is scoped to a
 * vehicle; callers pass a vehicle already checked to belong to the user.
 */
final readonly class MaintenanceEntryRepository
{
    private const string TABLE = 'maintenance_entries';
    private const int KM_SCALE = 3;
    private const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<MaintenanceEntry> in the order the work was done
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->ordered($this->select()->where('vehicle_id = :vehicle'))
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The entries of several vehicles between two calendar dates.
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until exclusive; null = to the end
     * @return list<MaintenanceEntry> in the order the work was done
     */
    public function listForVehiclesBetween(array $vehicleIds, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($from !== null) {
            $query->andWhere('performed_on >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $query->andWhere('performed_on < :until')->setParameter('until', $until->format('Y-m-d'));
        }

        return array_values(array_map($this->hydrate(...), $this->ordered($query)->fetchAllAssociative()));
    }

    /**
     * @return list<MaintenanceEntry> the entries that complete one schedule
     */
    public function listForSchedule(int $vehicleId, int $scheduleId): array
    {
        $rows = $this->ordered($this->select()->where('vehicle_id = :vehicle', 'schedule_id = :schedule'))
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('schedule', $scheduleId, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?MaintenanceEntry
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, MaintenanceEntryData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), ['vehicle_id' => ParameterType::INTEGER] + self::types($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, MaintenanceEntryData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER] + self::types($data),
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

    /**
     * Detach entries from a schedule that is being deleted (the foreign key
     * does the same; this keeps it right where constraints are off).
     */
    public function unlinkSchedule(int $vehicleId, int $scheduleId): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('schedule_id', 'NULL')
            ->where('vehicle_id = :vehicle', 'schedule_id = :schedule')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('schedule', $scheduleId, ParameterType::INTEGER)
            ->executeStatement();
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'schedule_id', 'performed_on', 'odometer_km', 'category', 'title')
            ->addSelect('description', 'cost', 'vendor', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * By date, then in the order logged. (Not by odometer: it may be null,
     * and engines disagree on where nulls sort.)
     */
    private function ordered(QueryBuilder $query): QueryBuilder
    {
        return $query->orderBy('performed_on')->addOrderBy('id');
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function dataColumns(MaintenanceEntryData $data): array
    {
        return [
            'schedule_id' => $data->scheduleId,
            'performed_on' => $data->performedOn->format('Y-m-d'),
            'odometer_km' => $data->odometerKm,
            'category' => $data->category->value,
            'title' => $data->title,
            'description' => $data->description,
            'cost' => $data->cost,
            'vendor' => $data->vendor,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(MaintenanceEntryData $data): array
    {
        return ['schedule_id' => $data->scheduleId === null ? ParameterType::NULL : ParameterType::INTEGER];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MaintenanceEntry
    {
        $platform = $this->connection->getDatabasePlatform();

        return new MaintenanceEntry(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new MaintenanceEntryData(
                performedOn: Row::nullableDate($row, 'performed_on')
                    ?? throw new UnexpectedValueException('Column "performed_on" is null.'),
                category: MaintenanceCategory::tryFrom(Row::string($row, 'category')) ?? MaintenanceCategory::Other,
                title: Row::string($row, 'title'),
                cost: Row::decimal($row, 'cost', self::MONEY_SCALE),
                odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
                vendor: Row::nullableString($row, 'vendor'),
                description: Row::nullableString($row, 'description'),
                scheduleId: Row::nullableInt($row, 'schedule_id'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
