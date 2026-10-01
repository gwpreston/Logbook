<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The data checks each user has hidden (`attention_hidden`, spec.md §6
 * AttentionHidden, §7.24): one row per user, kind and subject, with the
 * fingerprint of what was judged when they hid it.
 */
final readonly class AttentionHiddenRepository
{
    private const string TABLE = 'attention_hidden';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The user's hidden checks on these vehicles, as fingerprints by key().
     *
     * @param list<int> $vehicleIds
     * @return array<string, string>
     */
    public function fingerprints(int $userId, array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('kind', 'subject_id', 'fingerprint')
            ->from(self::TABLE)
            ->where('user_id = :user', 'vehicle_id IN (:vehicles)')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();

        $hidden = [];
        foreach ($rows as $row) {
            $kind = AttentionKind::hideable(Row::string($row, 'kind'));
            if ($kind !== null) {
                $hidden[self::key($kind, Row::int($row, 'subject_id'))] = Row::string($row, 'fingerprint');
            }
        }

        return $hidden;
    }

    /**
     * Hide a check for the user; hiding it again replaces the fingerprint.
     */
    public function hide(
        int $userId,
        int $vehicleId,
        AttentionKind $kind,
        int $subjectId,
        string $fingerprint,
        DateTimeImmutable $now,
    ): void {
        $row = [
            'vehicle_id' => $vehicleId,
            'fingerprint' => $fingerprint,
            'hidden_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ];
        $where = ['user_id' => $userId, 'kind' => $kind->value, 'subject_id' => $subjectId];

        if ($this->connection->update(self::TABLE, $row, $where) > 0) {
            return;
        }
        try {
            $this->connection->insert(self::TABLE, $where + $row);
        } catch (UniqueConstraintViolationException) {
            // Hidden at the same moment from another tab: keep the latest.
            $this->connection->update(self::TABLE, $row, $where);
        }
    }

    public static function key(AttentionKind $kind, int $subjectId): string
    {
        return $kind->value . '|' . $subjectId;
    }
}
