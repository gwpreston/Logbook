<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartCondition;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Parts (`parts`) and their spells on the vehicle (`part_fittings`), spec.md
 * §6 Part and PartFitting. Vehicle queries are scoped to a vehicle the
 * caller has already resolved; fittings are reached through their part.
 */
final readonly class PartRepository
{
    private const string TABLE = 'parts';
    private const string FITTINGS = 'part_fittings';
    /** The request-read group of a vehicle's fittings: read joined to `parts`, so forgotten on a write to either. */
    private const string FITTINGS_BY_VEHICLE = 'part_fittings+parts';
    public const int KM_SCALE = 3;
    public const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @param list<PartStatus> $statuses none = every status
     * @return list<Part> by name
     */
    public function listForVehicle(int $vehicleId, array $statuses = []): array
    {
        $all = $this->reads->remember(self::TABLE, $vehicleId, fn (): array => $this->listForVehicles([$vehicleId]));
        if ($statuses === []) {
            return $all;
        }

        return array_values(array_filter($all, static fn (Part $p): bool => in_array($p->status, $statuses, true)));
    }

    /**
     * @param list<int> $vehicleIds
     */
    public function prime(array $vehicleIds): void
    {
        $this->reads->prime(self::TABLE, $vehicleIds, fn (array $ids): array => RequestReads::groupBy(
            $this->listForVehicles($ids),
            static fn (Part $p): int => $p->vehicleId,
        ), []);
        if (!$this->reads->isActive()) {
            return;
        }
        // Their fittings too, in one more query, for the vehicles that hold parts.
        $holding = array_values(array_filter($vehicleIds, fn (int $id): bool => $this->listForVehicle($id) !== []));
        $this->reads->prime(self::FITTINGS_BY_VEHICLE, $holding, $this->fittingsOfVehicles(...), []);
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<Part> by name, then as added
     */
    public function listForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $parts = array_values(array_map($this->hydrate(...), $rows));
        usort($parts, static fn (Part $a, Part $b): int
            => strnatcasecmp($a->label(), $b->label()) ?: $a->id <=> $b->id);

        return $parts;
    }

    public function find(int $vehicleId, int $id): ?Part
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, PartData $data, DateTimeImmutable $now, ?int $createdBy): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'status' => PartStatus::Stored->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), [
            'vehicle_id' => ParameterType::INTEGER,
            'created_by' => ParameterType::INTEGER,
            'warranty_months' => ParameterType::INTEGER,
            'warranty_reminder' => ParameterType::BOOLEAN,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, PartData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            [
                'vehicle_id' => ParameterType::INTEGER,
                'id' => ParameterType::INTEGER,
                'warranty_months' => ParameterType::INTEGER,
                'warranty_reminder' => ParameterType::BOOLEAN,
            ],
        );
    }

    public function setStatus(int $id, PartStatus $status): void
    {
        $this->connection->update(
            self::TABLE,
            ['status' => $status->value],
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Deleting removes its fittings and their readings (`CASCADE`).
     */
    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    // Fittings

    /**
     * @return list<PartFitting> oldest first
     */
    public function fittingsOf(int $partId): array
    {
        return $this->fittingsFor([$partId])[$partId] ?? [];
    }

    /**
     * @param list<int> $partIds
     * @return array<int, list<PartFitting>> by part id, oldest first
     */
    public function fittingsFor(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }
        $rows = $this->selectFittings()
            ->where('part_id IN (:parts)')
            ->setParameter('parts', $partIds, ArrayParameterType::INTEGER)
            ->orderBy('fitted_on')
            ->addOrderBy('id')
            ->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $fitting = $this->hydrateFitting($row);
            $map[$fitting->partId][] = $fitting;
        }

        return $map;
    }

    /**
     * Every fitting of a vehicle's parts, by part id, oldest first: read once
     * per request (spec.md §8 *Page budgets*), and not at all for a vehicle
     * without parts.
     *
     * @return array<int, list<PartFitting>>
     */
    public function fittingsForVehicle(int $vehicleId): array
    {
        if ($this->listForVehicle($vehicleId) === []) {
            return [];
        }

        return $this->reads->remember(
            self::FITTINGS_BY_VEHICLE,
            $vehicleId,
            fn (): array => $this->fittingsOfVehicles([$vehicleId])[$vehicleId] ?? [],
        );
    }


    /**
     * @param list<int> $vehicleIds
     * @return array<int, array<int, list<PartFitting>>> by vehicle id, then part id
     */
    private function fittingsOfVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->selectFittings('f')
            ->addSelect('p.vehicle_id AS vehicle_id')
            ->innerJoin('f', self::TABLE, 'p', 'p.id = f.part_id')
            ->where('p.vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->orderBy('f.fitted_on')
            ->addOrderBy('f.id')
            ->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $fitting = $this->hydrateFitting($row);
            $map[Row::int($row, 'vehicle_id')][$fitting->partId][] = $fitting;
        }

        return $map;
    }

    /**
     * The fittings a record made or ended: a service record's or a modification's.
     *
     * @param 'maintenance'|'modification' $kind
     * @return list<PartFitting> oldest first
     */
    public function fittingsByRecord(string $kind, int $recordId): array
    {
        return $this->fittingsByRecords($kind, [$recordId])[$recordId] ?? [];
    }

    /**
     * @param 'maintenance'|'modification' $kind
     * @param list<int> $recordIds
     * @return array<int, list<PartFitting>> by record id (a fitting fitted and removed by one record is listed once)
     */
    public function fittingsByRecords(string $kind, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }
        $fitted = 'fitted_' . $kind . '_id';
        $removed = 'removed_' . $kind . '_id';
        $rows = $this->selectFittings()
            ->where(sprintf('%s IN (:records) OR %s IN (:records)', $fitted, $removed))
            ->setParameter('records', $recordIds, ArrayParameterType::INTEGER)
            ->orderBy('fitted_on')
            ->addOrderBy('id')
            ->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $fitting = $this->hydrateFitting($row);
            $ids = array_unique(array_filter([
                $kind === 'maintenance' ? $fitting->fittedMaintenanceId : $fitting->fittedModificationId,
                $kind === 'maintenance' ? $fitting->removedMaintenanceId : $fitting->removedModificationId,
            ], static fn (?int $id): bool => $id !== null && in_array($id, $recordIds, true)));
            foreach ($ids as $id) {
                $map[$id][] = $fitting;
            }
        }

        return $map;
    }


    /**
     * The part a fitting belongs to, on this vehicle (a reading's owner, Mileage tab).
     */
    public function partOfFitting(int $vehicleId, int $fittingId): ?int
    {
        $id = $this->connection->createQueryBuilder()
            ->select('f.part_id')
            ->from(self::FITTINGS, 'f')
            ->innerJoin('f', self::TABLE, 'p', 'p.id = f.part_id')
            ->where('f.id = :id', 'p.vehicle_id = :vehicle')
            ->setParameter('id', $fittingId, ParameterType::INTEGER)
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param 'maintenance'|'modification'|null $kind the record that fitted it
     */
    public function insertFitting(
        int $partId,
        DateTimeImmutable $fittedOn,
        ?string $odometerKm,
        ?string $kind,
        ?int $recordId,
        DateTimeImmutable $now,
        bool $byRevert = false,
    ): int {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::FITTINGS, [
            'part_id' => $partId,
            'fitted_on' => $fittedOn->format('Y-m-d'),
            'fitted_odometer_km' => $odometerKm,
            'fitted_maintenance_id' => $kind === 'maintenance' ? $recordId : null,
            'fitted_modification_id' => $kind === 'modification' ? $recordId : null,
            'fitted_by_revert' => $byRevert,
            'removed_by_revert' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], [
            'part_id' => ParameterType::INTEGER,
            'fitted_maintenance_id' => ParameterType::INTEGER,
            'fitted_modification_id' => ParameterType::INTEGER,
            'fitted_by_revert' => ParameterType::BOOLEAN,
            'removed_by_revert' => ParameterType::BOOLEAN,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function moveFitted(int $id, DateTimeImmutable $on, ?string $odometerKm, DateTimeImmutable $now): void
    {
        $this->updateFitting($id, [
            'fitted_on' => $on->format('Y-m-d'),
            'fitted_odometer_km' => $odometerKm,
        ], $now);
    }

    /**
     * End a fitting (or edit how it ended).
     *
     * @param 'maintenance'|'modification'|null $kind the record that removed it
     */
    public function setRemoved(
        int $id,
        DateTimeImmutable $on,
        ?string $odometerKm,
        ?string $kind,
        ?int $recordId,
        RemovalOutcome $outcome,
        ?string $soldPrice,
        ?int $replacedBy,
        DateTimeImmutable $now,
        bool $byRevert = false,
    ): void {
        $this->updateFitting($id, [
            'removed_on' => $on->format('Y-m-d'),
            'removed_odometer_km' => $odometerKm,
            'removed_maintenance_id' => $kind === 'maintenance' ? $recordId : null,
            'removed_modification_id' => $kind === 'modification' ? $recordId : null,
            'removed_by_revert' => $byRevert,
            'outcome' => $outcome->value,
            'sold_price' => $outcome === RemovalOutcome::Sold ? $soldPrice : null,
            'replaced_by_part_id' => $outcome === RemovalOutcome::Returned ? $replacedBy : null,
        ], $now);
    }

    /**
     * Reopen a fitting: it is on the vehicle again.
     */
    public function clearRemoved(int $id, DateTimeImmutable $now): void
    {
        $this->updateFitting($id, [
            'removed_on' => null,
            'removed_odometer_km' => null,
            'removed_maintenance_id' => null,
            'removed_modification_id' => null,
            'removed_by_revert' => false,
            'outcome' => null,
            'sold_price' => null,
            'replaced_by_part_id' => null,
        ], $now);
    }

    public function deleteFitting(int $id): void
    {
        $this->connection->delete(self::FITTINGS, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * @param array<string, bool|string|int|null> $columns
     */
    private function updateFitting(int $id, array $columns, DateTimeImmutable $now): void
    {
        $columns['updated_at'] = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->update(self::FITTINGS, $columns, ['id' => $id], [
            'id' => ParameterType::INTEGER,
            'removed_maintenance_id' => ParameterType::INTEGER,
            'removed_modification_id' => ParameterType::INTEGER,
            'removed_by_revert' => ParameterType::BOOLEAN,
            'replaced_by_part_id' => ParameterType::INTEGER,
        ]);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'created_by', 'name', 'brand', 'part_number', 'serial_number', 'supplier')
            ->addSelect('bought_on', 'price', 'part_condition', 'origin', 'warranty_months', 'warranty_km')
            ->addSelect('warranty_from', 'warranty_provider', 'warranty_terms', 'warranty_reminder', 'notes', 'status')
            ->addSelect('created_at', 'updated_at')
            ->from(self::TABLE);
    }

    private function selectFittings(string $alias = ''): QueryBuilder
    {
        $p = $alias === '' ? '' : $alias . '.';
        $columns = [
            'id', 'part_id', 'fitted_on', 'fitted_odometer_km', 'fitted_maintenance_id', 'fitted_modification_id',
            'fitted_by_revert', 'removed_on', 'removed_odometer_km', 'removed_maintenance_id',
            'removed_modification_id', 'removed_by_revert', 'outcome', 'sold_price', 'replaced_by_part_id',
            'created_at', 'updated_at',
        ];

        return $this->connection->createQueryBuilder()
            ->select(...array_map(static fn (string $c): string => $p . $c, $columns))
            ->from(self::FITTINGS, $alias === '' ? null : $alias);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private static function dataColumns(PartData $data): array
    {
        $warranty = $data->hasWarranty();

        return [
            'name' => $data->name,
            'brand' => $data->brand,
            'part_number' => $data->partNumber,
            'serial_number' => $data->serialNumber,
            'supplier' => $data->supplier,
            'bought_on' => $data->boughtOn?->format('Y-m-d'),
            'price' => $data->price,
            'part_condition' => $data->condition->value,
            'origin' => $data->origin->value,
            'warranty_months' => $data->warrantyMonths,
            'warranty_km' => $data->warrantyKm,
            'warranty_from' => $warranty ? $data->warrantyFrom?->value : null,
            'warranty_provider' => $data->warrantyProvider,
            'warranty_terms' => $data->warrantyTerms,
            'warranty_reminder' => $warranty && $data->warrantyReminder,
            'notes' => $data->notes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Part
    {
        $platform = $this->connection->getDatabasePlatform();
        $from = Row::nullableString($row, 'warranty_from');

        return new Part(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new PartData(
                name: Row::string($row, 'name'),
                brand: Row::nullableString($row, 'brand'),
                partNumber: Row::nullableString($row, 'part_number'),
                serialNumber: Row::nullableString($row, 'serial_number'),
                supplier: Row::nullableString($row, 'supplier'),
                boughtOn: Row::nullableDate($row, 'bought_on'),
                price: Row::nullableDecimal($row, 'price', self::MONEY_SCALE),
                condition: PartCondition::tryFrom(Row::string($row, 'part_condition')) ?? PartCondition::New,
                origin: PartOrigin::tryFrom(Row::string($row, 'origin')) ?? PartOrigin::Fitted,
                warrantyMonths: Row::nullableInt($row, 'warranty_months'),
                warrantyKm: Row::nullableDecimal($row, 'warranty_km', self::KM_SCALE),
                warrantyFrom: $from === null ? null : WarrantyStart::tryFrom($from),
                warrantyProvider: Row::nullableString($row, 'warranty_provider'),
                warrantyTerms: Row::nullableString($row, 'warranty_terms'),
                notes: Row::nullableString($row, 'notes'),
                warrantyReminder: Row::bool($row, 'warranty_reminder'),
            ),
            status: PartStatus::tryFrom(Row::string($row, 'status')) ?? PartStatus::Stored,
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateFitting(array $row): PartFitting
    {
        $platform = $this->connection->getDatabasePlatform();
        $outcome = Row::nullableString($row, 'outcome');

        return new PartFitting(
            id: Row::int($row, 'id'),
            partId: Row::int($row, 'part_id'),
            fittedOn: Row::nullableDate($row, 'fitted_on') ?? throw new UnexpectedValueException('Column "fitted_on" is null.'),
            fittedOdometerKm: Row::nullableDecimal($row, 'fitted_odometer_km', self::KM_SCALE),
            fittedMaintenanceId: Row::nullableInt($row, 'fitted_maintenance_id'),
            fittedModificationId: Row::nullableInt($row, 'fitted_modification_id'),
            fittedByRevert: Row::bool($row, 'fitted_by_revert'),
            removedOn: Row::nullableDate($row, 'removed_on'),
            removedOdometerKm: Row::nullableDecimal($row, 'removed_odometer_km', self::KM_SCALE),
            removedMaintenanceId: Row::nullableInt($row, 'removed_maintenance_id'),
            removedModificationId: Row::nullableInt($row, 'removed_modification_id'),
            removedByRevert: Row::bool($row, 'removed_by_revert'),
            outcome: $outcome === null ? null : RemovalOutcome::tryFrom($outcome),
            soldPrice: Row::nullableDecimal($row, 'sold_price', self::MONEY_SCALE),
            replacedByPartId: Row::nullableInt($row, 'replaced_by_part_id'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
