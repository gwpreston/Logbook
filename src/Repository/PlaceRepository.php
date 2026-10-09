<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\PlaceData;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * A user's saved places (`places`, spec.md §6 Place). Every query is scoped
 * to the user: places are private to them.
 */
final readonly class PlaceRepository
{
    private const string TABLE = 'places';
    public const int POSITION_SCALE = 6;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @return list<Place> in the user's order
     */
    public function listForUser(int $userId): array
    {
        return $this->reads->remember(self::TABLE, $userId, function () use ($userId): array {
            $rows = $this->select()
                ->where('user_id = :user')
                ->setParameter('user', $userId, ParameterType::INTEGER)
                ->orderBy('sort_order')
                ->addOrderBy('id')
                ->fetchAllAssociative();

            return array_values(array_map($this->hydrate(...), $rows));
        });
    }

    public function find(int $userId, int $id): ?Place
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
    public function insert(int $userId, PlaceData $data, DateTimeImmutable $now): int
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
        ] + self::dataColumns($data), [
            'user_id' => ParameterType::INTEGER,
            'sort_order' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $userId, int $id, PlaceData $data, DateTimeImmutable $now): void
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
            ->select('id', 'user_id', 'name', 'latitude', 'longitude', 'sort_order', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @return array<string, string>
     */
    private static function dataColumns(PlaceData $data): array
    {
        return ['name' => $data->name, 'latitude' => $data->latitude, 'longitude' => $data->longitude];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Place
    {
        $platform = $this->connection->getDatabasePlatform();

        return new Place(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            data: new PlaceData(
                name: Row::string($row, 'name'),
                latitude: Row::decimal($row, 'latitude', self::POSITION_SCALE),
                longitude: Row::decimal($row, 'longitude', self::POSITION_SCALE),
            ),
            sortOrder: Row::int($row, 'sort_order'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
