<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * A user's saved journeys (`saved_journeys`, spec.md §6 SavedJourney).
 * Every query is scoped to the user.
 */
final readonly class SavedJourneyRepository
{
    private const string TABLE = 'saved_journeys';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<SavedJourney> in the user's order
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('sort_order')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $userId, int $id): ?SavedJourney
    {
        $row = $this->select()
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Added at the end of the user's list.
     */
    public function insert(int $userId, SavedJourneyData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $last = $this->connection->createQueryBuilder()
            ->select('MAX(sort_order)')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchOne();

        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'sort_order' => is_numeric($last) ? (int) $last + 1 : 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), self::types() + [
            'user_id' => ParameterType::INTEGER,
            'sort_order' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $userId, int $id, SavedJourneyData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['user_id' => $userId, 'id' => $id],
            self::types() + ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Put the user's journeys in this order; ids that are not theirs are ignored.
     *
     * @param list<int> $ids
     */
    public function reorder(int $userId, array $ids, DateTimeImmutable $now): void
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->transactional(function (Connection $connection) use ($userId, $ids, $timestamp): void {
            foreach ($ids as $position => $id) {
                $connection->update(
                    self::TABLE,
                    ['sort_order' => $position, 'updated_at' => $timestamp],
                    ['user_id' => $userId, 'id' => $id],
                    ['sort_order' => ParameterType::INTEGER, 'user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
                );
            }
        });
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
                'from_place',
                'to_place',
                'distance_km',
                'is_return_default',
                'purpose_default',
                'is_business_default',
                'sort_order',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, bool|string|null>
     */
    private static function dataColumns(SavedJourneyData $data): array
    {
        return [
            'from_place' => $data->fromPlace,
            'to_place' => $data->toPlace,
            'distance_km' => $data->distanceKm,
            'is_return_default' => $data->isReturnDefault,
            'purpose_default' => $data->purposeDefault,
            'is_business_default' => $data->isBusinessDefault,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return ['is_return_default' => ParameterType::BOOLEAN, 'is_business_default' => ParameterType::BOOLEAN];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedJourney
    {
        $platform = $this->connection->getDatabasePlatform();

        return new SavedJourney(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            data: new SavedJourneyData(
                fromPlace: Row::string($row, 'from_place'),
                toPlace: Row::string($row, 'to_place'),
                distanceKm: Row::decimal($row, 'distance_km', TripRepository::DISTANCE_SCALE),
                isReturnDefault: Row::bool($row, 'is_return_default'),
                purposeDefault: Row::nullableString($row, 'purpose_default'),
                isBusinessDefault: Row::bool($row, 'is_business_default'),
            ),
            sortOrder: Row::int($row, 'sort_order'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
