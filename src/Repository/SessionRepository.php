<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Server-side session storage (`sessions` table). Ids are HMACs of the
 * cookie token, so the table alone cannot be used to hijack a session.
 */
final readonly class SessionRepository
{
    private const string TABLE = 'sessions';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{data: array<string, mixed>, last_activity_at: DateTimeImmutable}|null
     */
    public function findActive(string $id, DateTimeImmutable $activeSince): ?array
    {
        $platform = $this->connection->getDatabasePlatform();

        $row = $this->connection->createQueryBuilder()
            ->select('data', 'last_activity_at')
            ->from(self::TABLE)
            ->where('id = :id', 'last_activity_at >= :since')
            ->setParameter('id', $id)
            ->setParameter('since', UtcDateTime::toDatabase($activeSince, $platform))
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        try {
            $data = json_decode(Row::string($row, 'data'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return [
            'data' => is_array($data) ? self::stringKeys($data) : [],
            'last_activity_at' => UtcDateTime::fromDatabase($row['last_activity_at'], $platform),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $id, ?int $userId, array $data, DateTimeImmutable $now): void
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'id' => $id,
            'user_id' => $userId,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'last_activity_at' => $timestamp,
        ], ['user_id' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, ?int $userId, array $data, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('user_id', ':user')
            ->set('data', ':data')
            ->set('last_activity_at', ':now')
            ->where('id = :id')
            ->setParameter('user', $userId, $userId === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->setParameter('data', json_encode($data, JSON_THROW_ON_ERROR))
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('id', $id)
            ->executeStatement();
    }

    public function delete(string $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * Sign a user out everywhere, optionally except one session.
     */
    public function deleteForUser(int $userId, ?string $exceptId = null): void
    {
        $query = $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER);

        if ($exceptId !== null) {
            $query->andWhere('id <> :except')->setParameter('except', $exceptId);
        }

        $query->executeStatement();
    }

    /**
     * Each user's latest session activity: their last sign-in, as far as
     * the install knows (Settings → Users).
     *
     * @return array<int, DateTimeImmutable> user id => when
     */
    public function lastActivityByUser(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('user_id', 'MAX(last_activity_at) AS last_activity')
            ->from(self::TABLE)
            ->where('user_id IS NOT NULL')
            ->groupBy('user_id')
            ->fetchAllAssociative();
        $platform = $this->connection->getDatabasePlatform();
        $last = [];
        foreach ($rows as $row) {
            $last[Row::int($row, 'user_id')] = UtcDateTime::fromDatabase($row['last_activity'] ?? null, $platform);
        }

        return $last;
    }

    public function deleteInactiveSince(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('last_activity_at < :cutoff')
            ->setParameter('cutoff', UtcDateTime::toDatabase($cutoff, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
