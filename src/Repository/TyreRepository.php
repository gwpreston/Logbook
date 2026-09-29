<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Tyres, tyre sets, tyre changes and their lines (spec.md §6, §7.17). Every
 * query is scoped to a vehicle (lines through their change); callers pass a
 * vehicle already checked to belong to the user.
 */
final readonly class TyreRepository
{
    private const string TYRES = 'tyres';
    private const string SETS = 'tyre_sets';
    private const string CHANGES = 'tyre_changes';
    private const string LINES = 'tyre_change_lines';
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    // --- Tyres -----------------------------------------------------------------

    /**
     * @return list<Tyre> in the order they were added
     */
    public function listTyres(int $vehicleId): array
    {
        return $this->listTyresOf([$vehicleId]);
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<Tyre> in the order they were added
     */
    public function listTyresOf(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TYRES)
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrateTyre(...), $rows));
    }

    public function findTyre(int $vehicleId, int $id): ?Tyre
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TYRES)
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateTyre($row);
    }

    /**
     * A new tyre, stored until the replay places it.
     */
    public function insertTyre(int $vehicleId, TyreData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TYRES, [
            'vehicle_id' => $vehicleId,
            'status' => TyreStatus::Stored->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::tyreColumns($data), ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateTyre(int $vehicleId, int $id, TyreData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TYRES,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::tyreColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Store the replayed state (status, position; the reason is cleared
     * unless the tyre is retired).
     */
    public function storeState(int $vehicleId, int $id, TyreStatus $status, ?TyrePosition $position, DateTimeImmutable $now): void
    {
        $query = $this->connection->createQueryBuilder()
            ->update(self::TYRES)
            ->set('status', ':status')
            ->set('position', ':position')
            ->set('updated_at', ':now')
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('status', $status->value)
            ->setParameter('position', $position?->value)
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER);
        if ($status !== TyreStatus::Retired) {
            $query->set('retired_reason', 'NULL');
        }
        $query->executeStatement();
    }

    public function setRetiredReason(int $vehicleId, int $id, TyreRetireReason $reason): void
    {
        $this->connection->update(
            self::TYRES,
            ['retired_reason' => $reason->value],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function setTyreSet(int $vehicleId, int $id, ?int $setId): void
    {
        $this->connection->update(
            self::TYRES,
            ['set_id' => $setId],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            [
                'set_id' => $setId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'vehicle_id' => ParameterType::INTEGER,
                'id' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Delete a tyre with its lines.
     */
    public function deleteTyre(int $vehicleId, int $id): void
    {
        $this->connection->delete(self::LINES, ['tyre_id' => $id], ['tyre_id' => ParameterType::INTEGER]);
        $this->connection->delete(
            self::TYRES,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    // --- Sets ------------------------------------------------------------------

    /**
     * @return list<TyreSet> by name
     */
    public function listSets(int $vehicleId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::SETS)
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->orderBy('name')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrateSet(...), $rows));
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<TyreSet>
     */
    public function listSetsOf(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::SETS)
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrateSet(...), $rows));
    }

    public function findSet(int $vehicleId, int $id): ?TyreSet
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::SETS)
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateSet($row);
    }

    public function insertSet(int $vehicleId, TyreSetData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::SETS, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::setColumns($data), ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateSet(int $vehicleId, int $id, TyreSetData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::SETS,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::setColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function deleteSet(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::SETS,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    // --- Changes ---------------------------------------------------------------

    /**
     * @return list<TyreChange> in the order they were added (replay order is TyreChange::compare)
     */
    public function listChanges(int $vehicleId): array
    {
        return $this->withLines($this->changes()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchAllAssociative());
    }

    /**
     * The changes of several vehicles between two calendar dates (the
     * activity feed).
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until exclusive; null = to the end
     * @return list<TyreChange>
     */
    public function listChangesBetween(array $vehicleIds, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->changes()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($from !== null) {
            $query->andWhere('done_on >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $query->andWhere('done_on < :until')->setParameter('until', $until->format('Y-m-d'));
        }

        return $this->withLines($query->fetchAllAssociative());
    }

    /**
     * The changes linked to any of these service records.
     *
     * @param list<int> $vehicleIds
     * @param list<int> $entryIds
     * @return list<TyreChange>
     */
    public function listChangesForEntries(array $vehicleIds, array $entryIds): array
    {
        if ($vehicleIds === [] || $entryIds === []) {
            return [];
        }

        return $this->withLines($this->changes()
            ->where('vehicle_id IN (:vehicles)', 'maintenance_entry_id IN (:entries)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->setParameter('entries', $entryIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative());
    }

    public function findChange(int $vehicleId, int $id): ?TyreChange
    {
        $rows = $this->changes()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return $this->withLines($rows)[0] ?? null;
    }

    /**
     * @param list<TyreChangeLine> $lines
     */
    public function insertChange(
        int $vehicleId,
        TyreChangeKind $kind,
        TyreChangeData $data,
        array $lines,
        DateTimeImmutable $now,
    ): int {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::CHANGES, [
            'vehicle_id' => $vehicleId,
            'kind' => $kind->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::changeColumns($data), ['vehicle_id' => ParameterType::INTEGER] + self::changeTypes($data));
        $id = (int) $this->connection->lastInsertId();

        foreach ($lines as $line) {
            $this->connection->insert(self::LINES, [
                'change_id' => $id,
                'tyre_id' => $line->tyreId,
                'action' => $line->action->value,
                'position' => $line->position?->value,
            ], ['change_id' => ParameterType::INTEGER, 'tyre_id' => ParameterType::INTEGER]);
        }

        return $id;
    }

    public function updateChange(int $vehicleId, int $id, TyreChangeData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::CHANGES,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())]
                + self::changeColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER] + self::changeTypes($data),
        );
    }

    /**
     * Delete a change with its lines.
     */
    public function deleteChange(int $vehicleId, int $id): void
    {
        if ($this->findChange($vehicleId, $id) === null) {
            return;
        }
        $this->connection->delete(self::LINES, ['change_id' => $id], ['change_id' => ParameterType::INTEGER]);
        $this->connection->delete(
            self::CHANGES,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function changes(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'kind', 'done_on', 'odometer_km', 'maintenance_entry_id', 'note')
            ->addSelect('created_at', 'updated_at')
            ->from(self::CHANGES)
            ->orderBy('id');
    }

    /**
     * Hydrate change rows with their lines, read in one query.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<TyreChange>
     */
    private function withLines(array $rows): array
    {
        $rows = array_values($rows);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => Row::int($row, 'id'), $rows);
        $lines = [];
        $lineRows = $this->connection->createQueryBuilder()
            ->select('change_id', 'tyre_id', 'action', 'position')
            ->from(self::LINES)
            ->where('change_id IN (:changes)')
            ->setParameter('changes', $ids, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();
        foreach ($lineRows as $row) {
            $position = Row::nullableString($row, 'position');
            $lines[Row::int($row, 'change_id')][] = new TyreChangeLine(
                Row::int($row, 'tyre_id'),
                TyreLineAction::tryFrom(Row::string($row, 'action'))
                    ?? throw new UnexpectedValueException('Unknown tyre line action.'),
                $position === null ? null : TyrePosition::tryFrom($position),
            );
        }

        $platform = $this->connection->getDatabasePlatform();

        return array_map(static fn (array $row): TyreChange => new TyreChange(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            kind: TyreChangeKind::tryFrom(Row::string($row, 'kind'))
                ?? throw new UnexpectedValueException('Unknown tyre change kind.'),
            data: new TyreChangeData(
                doneOn: Row::nullableDate($row, 'done_on') ?? throw new UnexpectedValueException('Column "done_on" is null.'),
                odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
                maintenanceEntryId: Row::nullableInt($row, 'maintenance_entry_id'),
                note: Row::nullableString($row, 'note'),
            ),
            lines: $lines[Row::int($row, 'id')] ?? [],
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        ), $rows);
    }

    // --- Mapping ---------------------------------------------------------------

    /**
     * @return array<string, string|null>
     */
    private static function tyreColumns(TyreData $data): array
    {
        return [
            'brand' => $data->brand,
            'model' => $data->model,
            'size' => $data->size,
            'season' => $data->season?->value,
            'dot_code' => $data->dot?->code,
            'manufactured_on' => $data->dot?->manufacturedOn->format('Y-m-d'),
            'notes' => $data->notes,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function setColumns(TyreSetData $data): array
    {
        return ['name' => $data->name, 'storage_location' => $data->storageLocation, 'notes' => $data->notes];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function changeColumns(TyreChangeData $data): array
    {
        return [
            'done_on' => $data->doneOn->format('Y-m-d'),
            'odometer_km' => $data->odometerKm,
            'maintenance_entry_id' => $data->maintenanceEntryId,
            'note' => $data->note,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function changeTypes(TyreChangeData $data): array
    {
        return ['maintenance_entry_id' => $data->maintenanceEntryId === null ? ParameterType::NULL : ParameterType::INTEGER];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTyre(array $row): Tyre
    {
        $platform = $this->connection->getDatabasePlatform();
        $season = Row::nullableString($row, 'season');
        $position = Row::nullableString($row, 'position');
        $reason = Row::nullableString($row, 'retired_reason');
        $code = Row::nullableString($row, 'dot_code');
        $made = Row::nullableDate($row, 'manufactured_on');

        return new Tyre(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new TyreData(
                brand: Row::nullableString($row, 'brand'),
                model: Row::nullableString($row, 'model'),
                size: Row::nullableString($row, 'size'),
                season: $season === null ? null : TyreSeason::tryFrom($season),
                dot: $code === null || $made === null ? null : DotCode::fromStored($code, $made),
                notes: Row::nullableString($row, 'notes'),
            ),
            status: TyreStatus::tryFrom(Row::string($row, 'status')) ?? TyreStatus::Stored,
            position: $position === null ? null : TyrePosition::tryFrom($position),
            setId: Row::nullableInt($row, 'set_id'),
            retiredReason: $reason === null ? null : TyreRetireReason::tryFrom($reason),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSet(array $row): TyreSet
    {
        $platform = $this->connection->getDatabasePlatform();

        return new TyreSet(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new TyreSetData(
                name: Row::string($row, 'name'),
                storageLocation: Row::nullableString($row, 'storage_location'),
                notes: Row::nullableString($row, 'notes'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
