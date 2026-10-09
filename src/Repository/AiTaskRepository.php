<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Which model does which job (`ai_tasks`, spec.md §6 AiTask): one row per
 * assigned task.
 */
final readonly class AiTaskRepository
{
    private const string TABLE = 'ai_tasks';

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * @return array<string, AiTaskAssignment> keyed by task value
     */
    public function all(): array
    {
        return $this->reads->remember(self::TABLE, 'all', fn (): array => $this->readAll());
    }

    /**
     * @return array<string, AiTaskAssignment> keyed by task value
     */
    private function readAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('task', 'model_id', 'temperature', 'max_output_tokens')
            ->from(self::TABLE)
            ->fetchAllAssociative();

        $assignments = [];
        foreach ($rows as $row) {
            $task = AiTaskName::tryFrom(Row::string($row, 'task'));
            if ($task === null) {
                continue;
            }
            $assignments[$task->value] = new AiTaskAssignment(
                task: $task,
                modelId: Row::int($row, 'model_id'),
                temperature: Row::nullableDecimal($row, 'temperature', 2),
                maxOutputTokens: Row::nullableInt($row, 'max_output_tokens'),
            );
        }

        return $assignments;
    }

    public function find(AiTaskName $task): ?AiTaskAssignment
    {
        return $this->all()[$task->value] ?? null;
    }

    public function assign(AiTaskAssignment $assignment, DateTimeImmutable $now): void
    {
        $columns = [
            'model_id' => $assignment->modelId,
            'temperature' => $assignment->temperature,
            'max_output_tokens' => $assignment->maxOutputTokens,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ];
        $types = ['model_id' => ParameterType::INTEGER, 'max_output_tokens' => ParameterType::INTEGER];

        $this->connection->transactional(function (Connection $connection) use ($assignment, $columns, $types): void {
            $exists = $connection->createQueryBuilder()
                ->select('1')
                ->from(self::TABLE)
                ->where('task = :task')
                ->setParameter('task', $assignment->task->value)
                ->fetchOne() !== false;
            if ($exists) {
                $connection->update(self::TABLE, $columns, ['task' => $assignment->task->value], $types);

                return;
            }
            $connection->insert(self::TABLE, ['task' => $assignment->task->value] + $columns, $types);
        });
    }

    public function unassign(AiTaskName $task): void
    {
        $this->connection->delete(self::TABLE, ['task' => $task->value]);
    }

    /**
     * Unassign every task using the model (taken off its connection).
     */
    public function unassignModel(int $modelId): void
    {
        $this->connection->delete(self::TABLE, ['model_id' => $modelId], ['model_id' => ParameterType::INTEGER]);
    }
}
