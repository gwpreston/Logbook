<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Valuations (`vehicle_valuations`). Every query is scoped to a vehicle;
 * callers pass a vehicle already checked to belong to the user.
 */
final readonly class ValuationRepository
{
    private const string TABLE = 'vehicle_valuations';
    public const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @return list<VehicleValuation> oldest first: by date, then in the order added
     */
    public function listForVehicle(int $vehicleId): array
    {
        return $this->reads->remember(self::TABLE, $vehicleId, function () use ($vehicleId): array {
            $rows = $this->select()
                ->where('vehicle_id = :vehicle')
                ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
                ->orderBy('valued_on')
                ->addOrderBy('id')
                ->fetchAllAssociative();

            return array_values(array_map($this->hydrate(...), $rows));
        });
    }

    /**
     * Read every valuation of these vehicles in one query, so the page's later
     * listForVehicle() calls for them cost nothing (spec.md §8 *Page budgets*).
     *
     * @param list<int> $vehicleIds
     */
    public function prime(array $vehicleIds): void
    {
        $this->reads->prime(self::TABLE, $vehicleIds, fn (array $ids): array => RequestReads::groupBy(
            $this->listForVehiclesBetween($ids, null, null),
            static fn (VehicleValuation $valuation): int => $valuation->vehicleId,
        ), []);
    }

    /**
     * The valuations of several vehicles between two calendar dates.
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until exclusive; null = to the end
     * @return list<VehicleValuation> oldest first: by date, then in the order added
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
            $query->andWhere('valued_on >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $query->andWhere('valued_on < :until')->setParameter('until', $until->format('Y-m-d'));
        }
        $rows = $query->orderBy('valued_on')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?VehicleValuation
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, VehicleValuationData $data, DateTimeImmutable $now, ?int $createdBy = null): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'created_by' => $createdBy,
        ] + self::dataColumns($data), ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, VehicleValuationData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
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
            ->select('id', 'vehicle_id', 'valued_on', 'amount', 'source', 'notes', 'created_at', 'updated_at', 'created_by')
            ->from(self::TABLE);
    }

    /**
     * @return array<string, string|null>
     */
    private static function dataColumns(VehicleValuationData $data): array
    {
        return [
            'valued_on' => $data->valuedOn->format('Y-m-d'),
            'amount' => $data->amount,
            'source' => $data->source,
            'notes' => $data->notes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): VehicleValuation
    {
        $platform = $this->connection->getDatabasePlatform();

        return new VehicleValuation(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new VehicleValuationData(
                valuedOn: Row::nullableDate($row, 'valued_on')
                    ?? throw new UnexpectedValueException('Column "valued_on" is null.'),
                amount: Row::decimal($row, 'amount', self::MONEY_SCALE),
                source: Row::nullableString($row, 'source'),
                notes: Row::nullableString($row, 'notes'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
        );
    }
}
