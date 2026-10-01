<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * A connection's secrets (`ai_secrets`, spec.md §6 AiSecret), as stored:
 * sealed values or `env:` references. Only Service\Ai\SecretBox reads
 * them; they never reach a template, a backup or an export.
 */
final readonly class AiSecretRepository
{
    private const string TABLE = 'ai_secrets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, string> slot => stored value
     */
    public function forConnection(int $connectionId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('slot', 'value')
            ->from(self::TABLE)
            ->where('connection_id = :connection')
            ->setParameter('connection', $connectionId, ParameterType::INTEGER)
            ->orderBy('slot')
            ->fetchAllAssociative();

        $secrets = [];
        foreach ($rows as $row) {
            $secrets[Row::string($row, 'slot')] = Row::string($row, 'value');
        }

        return $secrets;
    }

    public function put(int $connectionId, string $slot, string $stored, DateTimeImmutable $now): void
    {
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $where = ['connection_id' => $connectionId, 'slot' => $slot];
        $types = ['connection_id' => ParameterType::INTEGER];

        $this->connection->transactional(function (Connection $connection) use ($where, $types, $stored, $at): void {
            $exists = $connection->createQueryBuilder()
                ->select('1')
                ->from(self::TABLE)
                ->where('connection_id = :connection', 'slot = :slot')
                ->setParameter('connection', $where['connection_id'], ParameterType::INTEGER)
                ->setParameter('slot', $where['slot'])
                ->fetchOne() !== false;
            if ($exists) {
                $connection->update(self::TABLE, ['value' => $stored, 'updated_at' => $at], $where, $types);

                return;
            }
            $connection->insert(self::TABLE, $where + ['value' => $stored, 'created_at' => $at, 'updated_at' => $at], $types);
        });
    }

    public function remove(int $connectionId, string $slot): void
    {
        $this->connection->delete(
            self::TABLE,
            ['connection_id' => $connectionId, 'slot' => $slot],
            ['connection_id' => ParameterType::INTEGER],
        );
    }
}
