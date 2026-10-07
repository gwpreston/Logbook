<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Support\Database\UtcDateTime;

/**
 * Notification secrets (`notification_secrets`, spec.md §6
 * NotificationSecret): sealed values or, for the installation's, `env:`
 * references. A null owner is the installation. Only
 * Service\Mail\NotificationSecrets reads the values; never backed up.
 */
final readonly class NotificationSecretRepository
{
    private const string TABLE = 'notification_secrets';

    public function __construct(private Connection $connection)
    {
    }

    public function find(?int $ownerId, string $name): ?string
    {
        $value = $this->owned($this->connection->createQueryBuilder()->select('value')->from(self::TABLE), $ownerId)
            ->andWhere('name = :name')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string> every stored value (for the job log's redaction)
     */
    public function all(): array
    {
        $values = $this->connection->createQueryBuilder()
            ->select('value')
            ->from(self::TABLE)
            ->fetchFirstColumn();

        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * Store a value, replacing the one before (one transaction: the unique
     * index does not cover a null owner on every engine).
     */
    public function put(?int $ownerId, string $name, string $value, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($ownerId, $name, $value, $now): void {
            $at = UtcDateTime::toDatabase($now, $connection->getDatabasePlatform());
            $created = $this->owned($connection->createQueryBuilder()->select('created_at')->from(self::TABLE), $ownerId)
                ->andWhere('name = :name')
                ->setParameter('name', $name)
                ->setMaxResults(1)
                ->fetchOne();
            $this->delete($connection, $ownerId, $name);
            $connection->insert(self::TABLE, [
                'owner_user_id' => $ownerId,
                'name' => $name,
                'value' => $value,
                'created_at' => is_string($created) ? $created : $at,
                'updated_at' => $at,
            ], ['owner_user_id' => $ownerId === null ? ParameterType::NULL : ParameterType::INTEGER]);
        });
    }

    public function remove(?int $ownerId, string $name): void
    {
        $this->delete($this->connection, $ownerId, $name);
    }

    private function delete(Connection $connection, ?int $ownerId, string $name): void
    {
        $this->owned($connection->createQueryBuilder()->delete(self::TABLE), $ownerId)
            ->andWhere('name = :name')
            ->setParameter('name', $name)
            ->executeStatement();
    }

    private function owned(QueryBuilder $query, ?int $ownerId): QueryBuilder
    {
        if ($ownerId === null) {
            return $query->where('owner_user_id IS NULL');
        }

        return $query->where('owner_user_id = :owner')->setParameter('owner', $ownerId, ParameterType::INTEGER);
    }
}
