<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Draft\DraftProposal;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Ask's drafted entries (`ai_drafts`, spec.md §6 AiDraft). A draft is read
 * only by its own user; another user's id is not found. *Add* claims a
 * draft with a conditional update, so it is applied once at most.
 */
final readonly class AiDraftRepository
{
    private const string TABLE = 'ai_drafts';
    /** Applied drafts are kept this long for their card, then deleted. */
    public const int KEEP_APPLIED_SECONDS = 86400;

    public function __construct(private Connection $connection)
    {
    }

    public function insert(int $userId, ?int $threadId, DraftProposal $proposal, DateTimeImmutable $now): int
    {
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'thread_id' => $threadId,
            'kind' => $proposal->kind->value,
            'vehicle_id' => $proposal->vehicleId,
            'input' => self::json($proposal->input),
            'card' => self::json($proposal->card),
            'form_values' => self::json($proposal->formValues),
            'created_at' => $this->time($now),
            'expires_at' => $this->time($now->modify('+' . AiDraft::TTL_SECONDS . ' seconds')),
        ], ['user_id' => ParameterType::INTEGER, 'thread_id' => ParameterType::INTEGER, 'vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function find(int $userId, int $id): ?AiDraft
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('id = :id', 'user_id = :user')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The user's drafts by id, for the cards of an answer.
     *
     * @param list<int> $ids
     * @return array<int, AiDraft> id → draft
     */
    public function findMany(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user', 'id IN (:ids)')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $drafts = [];
        foreach ($rows as $row) {
            $draft = $this->hydrate($row);
            $drafts[$draft->id] = $draft;
        }

        return $drafts;
    }

    /**
     * Mark a waiting draft as being applied. False when it was applied,
     * discarded or expired meanwhile: then nothing may be written.
     */
    public function claim(int $id, DateTimeImmutable $now): bool
    {
        $at = $this->time($now);

        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('applied_at', ':now')
            ->where('id = :id', 'applied_at IS NULL', 'discarded_at IS NULL', 'expires_at > :now')
            ->setParameter('now', $at)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    /**
     * The entry *Add* wrote, and its `updated_at` then (*Undo* checks it is untouched).
     */
    public function recordEntry(int $id, ?int $entryId, ?DateTimeImmutable $updatedAt): void
    {
        $this->connection->update(self::TABLE, [
            'applied_entry_id' => $entryId,
            'applied_updated_at' => $updatedAt === null ? null : $this->time($updatedAt),
        ], ['id' => $id], ['applied_entry_id' => ParameterType::INTEGER]);
    }

    /**
     * Close a waiting draft without writing anything (*Discard*), or close
     * an applied one after *Undo*. False when there was nothing to close.
     */
    public function discard(int $userId, int $id, DateTimeImmutable $now): bool
    {
        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('discarded_at', ':now')
            ->where('id = :id', 'user_id = :user', 'discarded_at IS NULL')
            ->setParameter('now', $this->time($now))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    /**
     * A waiting draft added through its *Edit* form: applied, with the
     * entry the form wrote and nothing to undo.
     */
    public function closeByForm(int $userId, int $id, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('applied_at', ':now')
            ->where('id = :id', 'user_id = :user', 'applied_at IS NULL', 'discarded_at IS NULL')
            ->setParameter('now', $this->time($now))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * The scheduled clean-up: drafts never applied once they expire, and
     * applied ones a day after *Add*.
     */
    public function deleteExpired(DateTimeImmutable $now): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('(applied_at IS NULL AND expires_at <= :now) OR applied_at <= :kept')
            ->setParameter('now', $this->time($now))
            ->setParameter('kept', $this->time($now->modify('-' . self::KEEP_APPLIED_SECONDS . ' seconds')))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AiDraft
    {
        $platform = $this->connection->getDatabasePlatform();
        $nullable = static fn (string $column): ?DateTimeImmutable => ($row[$column] ?? null) === null
            ? null
            : UtcDateTime::fromDatabase($row[$column], $platform);
        $values = [];
        foreach (self::decode(Row::nullableString($row, 'form_values')) as $name => $value) {
            if (is_string($value)) {
                $values[(string) $name] = $value;
            }
        }

        return new AiDraft(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            threadId: Row::nullableInt($row, 'thread_id'),
            kind: DraftKind::from(Row::string($row, 'kind')),
            vehicleId: Row::int($row, 'vehicle_id'),
            input: self::stringKeys(self::decode(Row::nullableString($row, 'input'))),
            card: self::stringKeys(self::decode(Row::nullableString($row, 'card'))),
            formValues: $values,
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            expiresAt: UtcDateTime::fromDatabase($row['expires_at'], $platform),
            discardedAt: $nullable('discarded_at'),
            appliedAt: $nullable('applied_at'),
            appliedEntryId: Row::nullableInt($row, 'applied_entry_id'),
            appliedUpdatedAt: $nullable('applied_updated_at'),
        );
    }

    private function time(DateTimeImmutable $value): string
    {
        return UtcDateTime::toDatabase($value, $this->connection->getDatabasePlatform());
    }

    /**
     * @return array<mixed>
     */
    private static function decode(?string $json): array
    {
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
