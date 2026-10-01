<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Outcome;
use Logbook\Domain\Ai\RequestRecord;
use Logbook\Domain\Ai\UsageLine;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The AI usage log (`ai_requests`, spec.md §6 AiRequest): counts, times and
 * outcomes, without content unless `AI_LOG_CONTENT=true`.
 */
final readonly class AiRequestRepository
{
    private const string TABLE = 'ai_requests';

    public function __construct(private Connection $connection)
    {
    }

    public function log(RequestRecord $record): void
    {
        $this->connection->insert(self::TABLE, [
            'user_id' => $record->userId,
            'task' => $record->task,
            'connection_id' => $record->connectionId,
            'model' => mb_substr($record->model, 0, 200),
            'tokens_in' => $record->tokensIn,
            'tokens_out' => $record->tokensOut,
            'duration_ms' => $record->durationMs,
            'outcome' => $record->outcome->value,
            'error_code' => $record->errorCode,
            'content' => $record->content,
            'created_at' => UtcDateTime::toDatabase($record->createdAt, $this->connection->getDatabasePlatform()),
        ], [
            'user_id' => ParameterType::INTEGER,
            'connection_id' => ParameterType::INTEGER,
            'tokens_in' => ParameterType::INTEGER,
            'tokens_out' => ParameterType::INTEGER,
            'duration_ms' => ParameterType::INTEGER,
        ]);
    }

    /**
     * Tokens in and out logged on a connection since a UTC instant (the
     * monthly cap).
     */
    public function tokensSince(int $connectionId, DateTimeImmutable $since): int
    {
        $sum = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(COALESCE(tokens_in, 0) + COALESCE(tokens_out, 0)), 0)')
            ->from(self::TABLE)
            ->where('connection_id = :connection', 'created_at >= :since')
            ->setParameter('connection', $connectionId, ParameterType::INTEGER)
            ->setParameter('since', UtcDateTime::toDatabase($since, $this->connection->getDatabasePlatform()))
            ->fetchOne();

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * Calls, tokens and failures since a UTC instant, per connection.
     *
     * @return list<UsageLine>
     */
    public function byConnectionSince(DateTimeImmutable $since): array
    {
        return $this->usage('connection_id', $since);
    }

    /**
     * The same, per task (`test` included).
     *
     * @return list<UsageLine>
     */
    public function byTaskSince(DateTimeImmutable $since): array
    {
        return $this->usage('task', $since);
    }

    /**
     * Delete rows created before the cutoff (retention); returns how many.
     */
    public function deleteBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('created_at < :cutoff')
            ->setParameter('cutoff', UtcDateTime::toDatabase($cutoff, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }

    /**
     * @param 'connection_id'|'task' $column
     * @return list<UsageLine>
     */
    private function usage(string $column, DateTimeImmutable $since): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                $column . ' AS usage_key',
                'COUNT(*) AS calls',
                'COALESCE(SUM(tokens_in), 0) AS tokens_in',
                'COALESCE(SUM(tokens_out), 0) AS tokens_out',
                'SUM(CASE WHEN outcome = :ok THEN 0 ELSE 1 END) AS failures',
            )
            ->from(self::TABLE)
            ->where('created_at >= :since')
            ->setParameter('ok', Outcome::Ok->value)
            ->setParameter('since', UtcDateTime::toDatabase($since, $this->connection->getDatabasePlatform()))
            ->groupBy($column)
            ->orderBy($column)
            ->fetchAllAssociative();

        return array_values(array_map(static fn (array $row): UsageLine => new UsageLine(
            key: $column === 'task' ? Row::nullableString($row, 'usage_key') : Row::nullableInt($row, 'usage_key'),
            calls: Row::int($row, 'calls'),
            tokensIn: Row::int($row, 'tokens_in'),
            tokensOut: Row::int($row, 'tokens_out'),
            failures: Row::int($row, 'failures'),
        ), $rows));
    }
}
