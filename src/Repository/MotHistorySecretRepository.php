<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The MOT history provider's credentials (`mot_history_secrets`, spec.md §6
 * MotHistorySecret): sealed or `env:NAME`, never backed up.
 */
final readonly class MotHistorySecretRepository
{
    private const string TABLE = 'mot_history_secrets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, string> stored values by slot
     */
    public function forProvider(string $provider): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('slot', 'value')
            ->from(self::TABLE)
            ->where('provider = :provider')
            ->setParameter('provider', $provider)
            ->fetchAllAssociative();
        $values = [];
        foreach ($rows as $row) {
            $values[Row::string($row, 'slot')] = Row::string($row, 'value');
        }

        return $values;
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

    public function put(string $provider, string $slot, string $value, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($provider, $slot, $value, $now): void {
            $connection->delete(self::TABLE, ['provider' => $provider, 'slot' => $slot]);
            $connection->insert(self::TABLE, [
                'provider' => $provider,
                'slot' => $slot,
                'value' => $value,
                'updated_at' => UtcDateTime::toDatabase($now, $connection->getDatabasePlatform()),
            ]);
        });
    }

    public function remove(string $provider, string $slot): void
    {
        $this->connection->delete(self::TABLE, ['provider' => $provider, 'slot' => $slot]);
    }
}
