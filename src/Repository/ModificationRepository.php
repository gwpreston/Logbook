<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Modifications (`modifications`, spec.md §6 Modification). Vehicle queries
 * are scoped to a vehicle the caller has already resolved.
 */
final readonly class ModificationRepository
{
    private const string TABLE = 'modifications';
    public const int KM_SCALE = 3;
    public const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @return list<Modification> current first, then reverted; newest done first within each
     */
    public function listForVehicle(int $vehicleId): array
    {
        return $this->reads->remember(self::TABLE, $vehicleId, fn (): array => $this->listForVehicles([$vehicleId]));
    }

    /**
     * Read every modification of these vehicles in one query, so the page's
     * later listForVehicle() calls cost nothing (spec.md §8 *Page budgets*).
     *
     * @param list<int> $vehicleIds
     */
    public function prime(array $vehicleIds): void
    {
        $this->reads->prime(self::TABLE, $vehicleIds, fn (array $ids): array => RequestReads::groupBy(
            $this->listForVehicles($ids),
            static fn (Modification $m): int => $m->vehicleId,
        ), []);
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<Modification> current first, then reverted; newest done first within each
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
        $list = array_values(array_map($this->hydrate(...), $rows));
        usort($list, self::order(...));

        return $list;
    }


    public function find(int $vehicleId, int $id): ?Modification
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, ModificationData $data, DateTimeImmutable $now, ?int $createdBy): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), [
            'vehicle_id' => ParameterType::INTEGER,
            'created_by' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, ModificationData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Set or clear the revert (all three null to undo it).
     */
    public function setReverted(
        int $vehicleId,
        int $id,
        ?DateTimeImmutable $on,
        ?string $odometerKm,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->connection->update(
            self::TABLE,
            [
                'reverted_on' => $on?->format('Y-m-d'),
                'reverted_odometer_km' => $on === null ? null : $odometerKm,
                'reverted_note' => $on === null ? null : $note,
                'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
            ],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Deleting removes its readings and the fittings it made (`CASCADE`);
     * the service reopens the removals it made first.
     */
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
            ->select('id', 'vehicle_id', 'created_by', 'title', 'category', 'done_on', 'odometer_km', 'cost')
            ->addSelect('fitted_by', 'notes', 'declared_on', 'reverted_on', 'reverted_odometer_km', 'reverted_note')
            ->addSelect('created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * Current first, then reverted; newest done first, then the latest logged.
     */
    private static function order(Modification $a, Modification $b): int
    {
        return [$a->isReverted(), $b->data->doneOn, $b->id] <=> [$b->isReverted(), $a->data->doneOn, $a->id];
    }

    /**
     * @return array<string, string|null>
     */
    private static function dataColumns(ModificationData $data): array
    {
        return [
            'title' => $data->title,
            'category' => $data->category->value,
            'done_on' => $data->doneOn->format('Y-m-d'),
            'odometer_km' => $data->odometerKm,
            'cost' => $data->cost,
            'fitted_by' => $data->fittedBy,
            'notes' => $data->notes,
            'declared_on' => $data->declaredOn?->format('Y-m-d'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Modification
    {
        $platform = $this->connection->getDatabasePlatform();

        return new Modification(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new ModificationData(
                title: Row::string($row, 'title'),
                category: ModificationCategory::tryFrom(Row::string($row, 'category')) ?? ModificationCategory::Other,
                doneOn: Row::nullableDate($row, 'done_on')
                    ?? throw new UnexpectedValueException('Column "done_on" is null.'),
                odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
                cost: Row::decimal($row, 'cost', self::MONEY_SCALE),
                fittedBy: Row::nullableString($row, 'fitted_by'),
                notes: Row::nullableString($row, 'notes'),
                declaredOn: Row::nullableDate($row, 'declared_on'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
            revertedOn: Row::nullableDate($row, 'reverted_on'),
            revertedOdometerKm: Row::nullableDecimal($row, 'reverted_odometer_km', self::KM_SCALE),
            revertedNote: Row::nullableString($row, 'reverted_note'),
        );
    }
}
