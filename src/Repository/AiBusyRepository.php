<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Logbook\Support\Database\UtcDateTime;

/**
 * One AI request at a time per user (`ai_busy`, spec.md §6 AiBusy, §7.25
 * *Limits*). The lock is the unique user: an insert that fails means busy.
 * Each attempt runs in its own (possibly nested) transaction, so a failed
 * insert never aborts a surrounding one on PostgreSQL.
 */
final readonly class AiBusyRepository
{
    private const string TABLE = 'ai_busy';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Take the user's lock until $expiresAt; false when a live one is held.
     * An expired lock (a request that died) is taken over.
     */
    public function acquire(int $userId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        if ($this->tryInsert($userId, $now, $expiresAt)) {
            return true;
        }

        $platform = $this->connection->getDatabasePlatform();
        $stale = $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('user_id = :user', 'expires_at < :now')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('now', UtcDateTime::toDatabase($now, $platform))
            ->executeStatement();

        return $stale > 0 && $this->tryInsert($userId, $now, $expiresAt);
    }

    public function release(int $userId): void
    {
        $this->connection->delete(self::TABLE, ['user_id' => $userId], ['user_id' => ParameterType::INTEGER]);
    }

    /**
     * Remove locks that expired (the scheduled task).
     */
    public function deleteExpired(DateTimeImmutable $now): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('expires_at < :now')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }

    /**
     * @phpstan-impure the row may exist or not on each call
     */
    private function tryInsert(int $userId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        $platform = $this->connection->getDatabasePlatform();
        try {
            $row = [
                'user_id' => $userId,
                'started_at' => UtcDateTime::toDatabase($now, $platform),
                'expires_at' => UtcDateTime::toDatabase($expiresAt, $platform),
            ];
            $this->connection->transactional(static function (Connection $connection) use ($row): void {
                $connection->insert(self::TABLE, $row, ['user_id' => ParameterType::INTEGER]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
