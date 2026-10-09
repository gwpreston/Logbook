<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Recurring schedules (`maintenance_schedules`). The computed columns
 * (last_done_*, next_due_*) are written only through setComputed(). Every
 * query is scoped to a vehicle.
 */
final readonly class MaintenanceScheduleRepository
{
    private const string TABLE = 'maintenance_schedules';
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @return list<MaintenanceSchedule> in creation order
     */
    public function listForVehicle(int $vehicleId): array
    {
        return $this->reads->remember(self::TABLE, $vehicleId, function () use ($vehicleId): array {
            $rows = $this->select()
                ->where('vehicle_id = :vehicle')
                ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
                ->orderBy('id')
                ->fetchAllAssociative();

            return array_values(array_map($this->hydrate(...), $rows));
        });
    }

    /**
     * Read every schedule of these vehicles in one query, so the page's later
     * listForVehicle() calls for them cost nothing (spec.md §8 *Page budgets*).
     *
     * @param list<int> $vehicleIds
     */
    public function prime(array $vehicleIds): void
    {
        $this->reads->prime(self::TABLE, $vehicleIds, function (array $ids): array {
            $rows = $this->select()
                ->where('vehicle_id IN (:vehicles)')
                ->setParameter('vehicles', $ids, ArrayParameterType::INTEGER)
                ->orderBy('id')
                ->fetchAllAssociative();

            return RequestReads::groupBy(
                array_values(array_map($this->hydrate(...), $rows)),
                static fn (MaintenanceSchedule $schedule): int => $schedule->vehicleId,
            );
        }, []);
    }

    public function find(int $vehicleId, int $id): ?MaintenanceSchedule
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, MaintenanceScheduleData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), ['vehicle_id' => ParameterType::INTEGER] + self::types($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, MaintenanceScheduleData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER] + self::types($data),
        );
    }

    /**
     * Store the computed last-done and next-due points.
     */
    public function setComputed(int $vehicleId, int $id, DonePoint $lastDone, NextDue $nextDue): void
    {
        $this->connection->update(self::TABLE, [
            'last_done_on' => $lastDone->on?->format('Y-m-d'),
            'last_done_km' => $lastDone->km,
            'next_due_on' => $nextDue->on?->format('Y-m-d'),
            'next_due_km' => $nextDue->km,
        ], ['vehicle_id' => $vehicleId, 'id' => $id], ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]);
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
            ->select('id', 'vehicle_id', 'category', 'title', 'interval_km', 'interval_months')
            ->addSelect('baseline_done_on', 'baseline_done_km', 'last_done_on', 'last_done_km', 'next_due_on', 'next_due_km')
            ->addSelect('created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function dataColumns(MaintenanceScheduleData $data): array
    {
        return [
            'category' => $data->category->value,
            'title' => $data->title,
            'interval_km' => $data->intervalKm,
            'interval_months' => $data->intervalMonths,
            'baseline_done_on' => $data->baselineDoneOn?->format('Y-m-d'),
            'baseline_done_km' => $data->baselineDoneKm,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(MaintenanceScheduleData $data): array
    {
        return ['interval_months' => $data->intervalMonths === null ? ParameterType::NULL : ParameterType::INTEGER];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MaintenanceSchedule
    {
        $platform = $this->connection->getDatabasePlatform();

        return new MaintenanceSchedule(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new MaintenanceScheduleData(
                category: MaintenanceCategory::tryFrom(Row::string($row, 'category')) ?? MaintenanceCategory::Other,
                title: Row::string($row, 'title'),
                intervalKm: Row::nullableDecimal($row, 'interval_km', self::KM_SCALE),
                intervalMonths: Row::nullableInt($row, 'interval_months'),
                baselineDoneOn: Row::nullableDate($row, 'baseline_done_on'),
                baselineDoneKm: Row::nullableDecimal($row, 'baseline_done_km', self::KM_SCALE),
            ),
            lastDone: new DonePoint(
                Row::nullableDate($row, 'last_done_on'),
                Row::nullableDecimal($row, 'last_done_km', self::KM_SCALE),
            ),
            nextDue: new NextDue(
                Row::nullableDate($row, 'next_due_on'),
                Row::nullableDecimal($row, 'next_due_km', self::KM_SCALE),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
