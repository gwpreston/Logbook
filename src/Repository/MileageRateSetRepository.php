<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Units\DistanceUnit;
use UnexpectedValueException;

/**
 * A user's mileage rate sets (`mileage_rate_sets`, spec.md §6
 * MileageRateSet). Every query is scoped to the user.
 */
final readonly class MileageRateSetRepository
{
    private const string TABLE = 'mileage_rate_sets';
    public const int RATE_SCALE = 4;
    public const int THRESHOLD_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<MileageRateSet> newest first
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('effective_from', 'DESC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $userId, int $id): ?MileageRateSet
    {
        $row = $this->select()
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Whether another of the user's sets starts on this date (the unique key).
     */
    public function startsOn(int $userId, DateTimeImmutable $date, ?int $exceptId = null): bool
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::TABLE)
            ->where('user_id = :user', 'effective_from = :from')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('from', $date->format('Y-m-d'));
        if ($exceptId !== null) {
            $query->andWhere('id <> :id')->setParameter('id', $exceptId, ParameterType::INTEGER);
        }

        return $query->fetchOne() !== false;
    }

    public function insert(int $userId, MileageRateSetData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), ['user_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $userId, int $id, MileageRateSetData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['user_id' => $userId, 'id' => $id],
            ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function delete(int $userId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['user_id' => $userId, 'id' => $id],
            ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'user_id',
                'effective_from',
                'distance_unit',
                'currency',
                'car_rate',
                'car_threshold',
                'car_rate_after',
                'bike_rate',
                'passenger_rate',
                'employer_car_rate',
                'employer_bike_rate',
                'source',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, string|null>
     */
    private static function dataColumns(MileageRateSetData $data): array
    {
        return [
            'effective_from' => $data->effectiveFrom->format('Y-m-d'),
            'distance_unit' => $data->distanceUnit->value,
            'currency' => $data->currency,
            'car_rate' => $data->carRate,
            'car_threshold' => $data->carThreshold,
            'car_rate_after' => $data->carRateAfter,
            'bike_rate' => $data->bikeRate,
            'passenger_rate' => $data->passengerRate,
            'employer_car_rate' => $data->employerCarRate,
            'employer_bike_rate' => $data->employerBikeRate,
            'source' => $data->source,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MileageRateSet
    {
        $platform = $this->connection->getDatabasePlatform();
        $rate = static fn (string $column): ?string => Row::nullableDecimal($row, $column, self::RATE_SCALE);

        return new MileageRateSet(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            data: new MileageRateSetData(
                effectiveFrom: Row::nullableDate($row, 'effective_from')
                    ?? throw new UnexpectedValueException('Column "effective_from" is null.'),
                distanceUnit: DistanceUnit::from(Row::string($row, 'distance_unit')),
                currency: Row::string($row, 'currency'),
                carRate: Row::decimal($row, 'car_rate', self::RATE_SCALE),
                carThreshold: Row::nullableDecimal($row, 'car_threshold', self::THRESHOLD_SCALE),
                carRateAfter: $rate('car_rate_after'),
                bikeRate: $rate('bike_rate'),
                passengerRate: $rate('passenger_rate'),
                employerCarRate: $rate('employer_car_rate'),
                employerBikeRate: $rate('employer_bike_rate'),
                source: Row::nullableString($row, 'source'),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
