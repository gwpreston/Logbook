<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\Ai\Ask\FeedbackMark;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Ask Logbook's threads and their messages (`ai_threads`, `ai_messages`,
 * spec.md §6 AiThread, AiMessage). Every read and change is scoped to the
 * thread's user. Not backed up.
 */
final readonly class AiThreadRepository
{
    private const string THREADS = 'ai_threads';
    private const string MESSAGES = 'ai_messages';
    /** TEXT on MySQL holds 64 KB. */
    private const int MAX_TEXT_BYTES = 60000;

    public function __construct(private Connection $connection)
    {
    }

    public function create(int $userId, string $title, DateTimeImmutable $now): AskThread
    {
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $title)), 0, 200);
        $this->connection->insert(self::THREADS, [
            'user_id' => $userId,
            'title' => $title,
            'created_at' => $at,
            'updated_at' => $at,
        ], ['user_id' => ParameterType::INTEGER]);

        return new AskThread((int) $this->connection->lastInsertId(), $userId, $title, $now, $now);
    }

    public function find(int $userId, int $threadId): ?AskThread
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'user_id', 'title', 'created_at', 'updated_at')
            ->from(self::THREADS)
            ->where('id = :id', 'user_id = :user')
            ->setParameter('id', $threadId, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->thread($row);
    }

    /**
     * @return list<AskThread> newest first
     */
    public function forUser(int $userId, int $limit = 50): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'user_id', 'title', 'created_at', 'updated_at')
            ->from(self::THREADS)
            ->where('user_id = :user')
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map($this->thread(...), $rows));
    }

    /**
     * @param list<ToolRun> $toolRuns
     * @param list<string> $ungrounded
     */
    public function addMessage(
        AskThread $thread,
        AskRole $role,
        string $content,
        DateTimeImmutable $now,
        array $toolRuns = [],
        array $ungrounded = [],
        ?string $connectionName = null,
        ?Location $location = null,
        ?string $model = null,
        ?ErrorCode $error = null,
    ): AskMessage {
        $platform = $this->connection->getDatabasePlatform();
        $at = UtcDateTime::toDatabase($now, $platform);
        $content = mb_strcut($content, 0, self::MAX_TEXT_BYTES);
        $this->connection->insert(self::MESSAGES, [
            'thread_id' => $thread->id,
            'role' => $role->value,
            'content' => $content,
            'tool_calls' => $toolRuns === [] ? null : self::json(array_map(
                static fn (ToolRun $run): array => $run->toArray(),
                $toolRuns,
            )),
            'grounding' => $ungrounded === [] ? null : self::json($ungrounded),
            'connection_name' => $connectionName === null ? null : mb_substr($connectionName, 0, 100),
            'location' => $location?->value,
            'model' => $model === null ? null : mb_substr($model, 0, 200),
            'error_code' => $error?->value,
            'feedback' => null,
            'created_at' => $at,
        ], ['thread_id' => ParameterType::INTEGER]);
        $id = (int) $this->connection->lastInsertId();
        $this->connection->update(
            self::THREADS,
            ['updated_at' => $at],
            ['id' => $thread->id],
            ['id' => ParameterType::INTEGER],
        );

        return new AskMessage(
            $id,
            $thread->id,
            $role,
            $content,
            $toolRuns,
            $ungrounded,
            $connectionName,
            $location,
            $model,
            $error,
            null,
            $now,
        );
    }

    /**
     * @return list<AskMessage> oldest first
     */
    public function messages(AskThread $thread): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'thread_id',
                'role',
                'content',
                'tool_calls',
                'grounding',
                'connection_name',
                'location',
                'model',
                'error_code',
                'feedback',
                'created_at',
            )
            ->from(self::MESSAGES)
            ->where('thread_id = :thread')
            ->orderBy('id', 'ASC')
            ->setParameter('thread', $thread->id, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map($this->message(...), $rows));
    }

    /**
     * Mark one of the user's answers; false when it isn't theirs or is no
     * answer. Returns the mark it had before, as the second value.
     *
     * @return array{bool, ?FeedbackMark}
     */
    public function mark(int $userId, int $messageId, FeedbackMark $mark): array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('m.feedback')
            ->from(self::MESSAGES, 'm')
            ->innerJoin('m', self::THREADS, 't', 't.id = m.thread_id')
            ->where('m.id = :id', 't.user_id = :user', 'm.role = :role', 'm.error_code IS NULL')
            ->setParameter('id', $messageId, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('role', AskRole::Assistant->value)
            ->fetchAssociative();
        if ($row === false) {
            return [false, null];
        }
        $this->connection->update(
            self::MESSAGES,
            ['feedback' => $mark->value],
            ['id' => $messageId],
            ['id' => ParameterType::INTEGER],
        );

        return [true, FeedbackMark::tryFrom(Row::nullableString($row, 'feedback') ?? '')];
    }

    public function delete(int $userId, int $threadId): bool
    {
        return $this->connection->delete(
            self::THREADS,
            ['id' => $threadId, 'user_id' => $userId],
            ['id' => ParameterType::INTEGER, 'user_id' => ParameterType::INTEGER],
        ) > 0;
    }

    public function deleteAll(int $userId): int
    {
        return (int) $this->connection->delete(self::THREADS, ['user_id' => $userId], ['user_id' => ParameterType::INTEGER]);
    }

    /**
     * @return list<int> users who have threads (the scheduled task works through them)
     */
    public function userIds(): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('DISTINCT user_id')
            ->from(self::THREADS)
            ->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    /**
     * Delete the user's threads whose last message is before $cutoff.
     * Messages go with them (`ON DELETE CASCADE`), deleted first here too
     * for SQLite without foreign keys switched on.
     */
    public function deleteBefore(int $userId, DateTimeImmutable $cutoff): int
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::THREADS)
            ->where('user_id = :user', 'updated_at < :cutoff')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('cutoff', UtcDateTime::toDatabase($cutoff, $this->connection->getDatabasePlatform()))
            ->fetchFirstColumn();
        if ($ids === []) {
            return 0;
        }
        $ids = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
        $this->connection->createQueryBuilder()
            ->delete(self::MESSAGES)
            ->where('thread_id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();

        return (int) $this->connection->createQueryBuilder()
            ->delete(self::THREADS)
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function thread(array $row): AskThread
    {
        $platform = $this->connection->getDatabasePlatform();

        return new AskThread(
            Row::int($row, 'id'),
            Row::int($row, 'user_id'),
            Row::string($row, 'title'),
            UtcDateTime::fromDatabase($row['created_at'], $platform),
            UtcDateTime::fromDatabase($row['updated_at'], $platform),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function message(array $row): AskMessage
    {
        $runs = [];
        foreach (self::decode(Row::nullableString($row, 'tool_calls')) as $item) {
            $run = is_array($item) ? ToolRun::fromArray($item) : null;
            if ($run !== null) {
                $runs[] = $run;
            }
        }
        $grounding = array_values(array_filter(self::decode(Row::nullableString($row, 'grounding')), is_string(...)));

        return new AskMessage(
            id: Row::int($row, 'id'),
            threadId: Row::int($row, 'thread_id'),
            role: AskRole::tryFrom(Row::string($row, 'role')) ?? AskRole::User,
            content: Row::string($row, 'content'),
            toolRuns: $runs,
            ungrounded: $grounding,
            connectionName: Row::nullableString($row, 'connection_name'),
            location: Location::tryFrom(Row::nullableString($row, 'location') ?? ''),
            model: Row::nullableString($row, 'model'),
            error: ErrorCode::tryFrom(Row::nullableString($row, 'error_code') ?? ''),
            feedback: FeedbackMark::tryFrom(Row::nullableString($row, 'feedback') ?? ''),
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
