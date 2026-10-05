<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Domain\Ai\Location;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * `ai_insights` (spec.md §7.26 *AI insights*): one row per user, the
 * latest day's set; saving replaces it.
 */
final readonly class AiInsightRepository
{
    private const string TABLE = 'ai_insights';

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $userId): ?AiInsightSet
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->set($row);
    }

    /**
     * @return array<int, string> user id → the day of their set
     */
    public function days(): array
    {
        $days = [];
        $rows = $this->connection->createQueryBuilder()->select('user_id', 'day')->from(self::TABLE)->fetchAllAssociative();
        foreach ($rows as $row) {
            $days[Row::int($row, 'user_id')] = Row::string($row, 'day');
        }

        return $days;
    }

    public function save(int $userId, AiInsightSet $set): void
    {
        $values = [
            'day' => $set->day,
            'insights' => $set->insights === [] ? null : self::json(array_map(
                static fn (AiInsight $insight): array => $insight->toArray(),
                $set->insights,
            )),
            'tool_calls' => $set->runs === [] ? null : self::json(array_map(
                static fn (ToolRun $run): array => $run->toArray(),
                $set->runs,
            )),
            'connection_name' => $set->connectionName === null ? null : mb_substr($set->connectionName, 0, 100),
            'location' => $set->location?->value,
            'model' => $set->model === null ? null : mb_substr($set->model, 0, 200),
            'error_code' => $set->error?->value,
            'created_at' => UtcDateTime::toDatabase($set->createdAt, $this->connection->getDatabasePlatform()),
        ];
        $this->connection->transactional(function (Connection $connection) use ($userId, $values): void {
            $connection->delete(self::TABLE, ['user_id' => $userId], ['user_id' => ParameterType::INTEGER]);
            $connection->insert(self::TABLE, ['user_id' => $userId] + $values, ['user_id' => ParameterType::INTEGER]);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function set(array $row): AiInsightSet
    {
        $insights = [];
        foreach (self::decode(Row::nullableString($row, 'insights')) as $item) {
            $insight = is_array($item) ? AiInsight::fromArray($item) : null;
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }
        $runs = [];
        foreach (self::decode(Row::nullableString($row, 'tool_calls')) as $item) {
            $run = is_array($item) ? ToolRun::fromArray($item) : null;
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return new AiInsightSet(
            day: Row::string($row, 'day'),
            insights: $insights,
            runs: $runs,
            connectionName: Row::nullableString($row, 'connection_name'),
            location: Location::tryFrom(Row::nullableString($row, 'location') ?? ''),
            model: Row::nullableString($row, 'model'),
            error: ErrorCode::tryFrom(Row::nullableString($row, 'error_code') ?? ''),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $this->connection->getDatabasePlatform()),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
