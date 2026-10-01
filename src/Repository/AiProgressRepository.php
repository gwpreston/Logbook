<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Ask\AskProgress;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Which tools a running question has started (`ai_progress`, spec.md §6
 * AiProgress), keyed by the token the page sent with it, so the page can
 * poll for progress lines while the answer is worked out. Each write is
 * its own statement outside any transaction, so a poll sees it at once.
 */
final readonly class AiProgressRepository
{
    private const string TABLE = 'ai_progress';

    public function __construct(private Connection $connection)
    {
    }

    public function start(int $userId, string $token, DateTimeImmutable $now): void
    {
        $this->connection->delete(self::TABLE, ['token' => $token]);
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'token' => $token,
            'tools' => '[]',
            'done' => false,
            'thread_id' => null,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['user_id' => ParameterType::INTEGER, 'done' => ParameterType::BOOLEAN]);
    }

    /**
     * @param list<string> $tools every tool started so far
     */
    public function tools(string $token, array $tools, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'tools' => json_encode($tools, JSON_THROW_ON_ERROR),
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['token' => $token]);
    }

    public function finish(string $token, ?int $threadId, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'done' => true,
            'thread_id' => $threadId,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['token' => $token], ['done' => ParameterType::BOOLEAN, 'thread_id' => ParameterType::INTEGER]);
    }

    public function find(int $userId, string $token): ?AskProgress
    {
        $row = $this->connection->createQueryBuilder()
            ->select('tools', 'done', 'thread_id')
            ->from(self::TABLE)
            ->where('token = :token', 'user_id = :user')
            ->setParameter('token', $token)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        $tools = json_decode(Row::nullableString($row, 'tools') ?? '[]', true);

        return new AskProgress(
            is_array($tools) ? array_values(array_filter($tools, is_string(...))) : [],
            Row::bool($row, 'done'),
            Row::nullableInt($row, 'thread_id'),
        );
    }

    public function deleteBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('updated_at < :cutoff')
            ->setParameter('cutoff', UtcDateTime::toDatabase($cutoff, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }
}
