<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Support\Database\UtcDateTime;

/**
 * Users' notification channels (`notification_channels`, spec.md §6
 * NotificationChannel). Every read and write is by user and kind: there is
 * no lookup by id, so one user's channel can never be reached through
 * another's request.
 */
final readonly class NotificationChannelRepository
{
    private const string TABLE = 'notification_channels';
    private const int ERROR_MAX = 255;

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $userId, string $kind): ?ChannelRecord
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->andWhere('kind = :kind')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('kind', $kind)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, ChannelRecord> by kind
     */
    public function forUser(int $userId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $record = $this->hydrate($row);
            $records[$record->kind] = $record;
        }

        return $records;
    }

    /**
     * Create or replace a channel's settings. Saving clears the failure
     * count and a switch-off (spec.md §7.11 *Switched off after failures*).
     *
     * @param array<string, mixed> $settings
     */
    public function save(int $userId, string $kind, array $settings, bool $enabled, DateTimeImmutable $now): void
    {
        $save = function (Connection $connection) use ($userId, $kind, $settings, $enabled, $now): void {
            $at = UtcDateTime::toDatabase($now, $connection->getDatabasePlatform());
            $values = [
                'enabled' => $enabled,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'failures' => 0,
                'switched_off_at' => null,
                'updated_at' => $at,
            ];
            $types = ['enabled' => ParameterType::BOOLEAN, 'switched_off_at' => ParameterType::NULL];
            $updated = $connection->update(self::TABLE, $values, ['user_id' => $userId, 'kind' => $kind], $types);
            if ($updated === 0) {
                $connection->insert(self::TABLE, $values + [
                    'user_id' => $userId,
                    'kind' => $kind,
                    'created_at' => $at,
                ], $types);
            }
        };
        $this->connection->transactional($save);
    }

    /**
     * Switch a channel on or off. Switching on clears the failure count and
     * a switch-off.
     */
    public function setEnabled(int $userId, string $kind, bool $enabled, DateTimeImmutable $now): void
    {
        $values = ['enabled' => $enabled, 'updated_at' => $this->at($now)];
        $types = ['enabled' => ParameterType::BOOLEAN];
        if ($enabled) {
            $values += ['failures' => 0, 'switched_off_at' => null];
            $types['switched_off_at'] = ParameterType::NULL;
        }
        $this->connection->update(self::TABLE, $values, ['user_id' => $userId, 'kind' => $kind], $types);
    }

    public function delete(int $userId, string $kind): void
    {
        $this->connection->delete(self::TABLE, ['user_id' => $userId, 'kind' => $kind]);
    }

    public function recordSuccess(int $userId, string $kind, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'last_status' => 'ok',
            'last_attempt_at' => $this->at($now),
            'last_error' => null,
            'failures' => 0,
        ], ['user_id' => $userId, 'kind' => $kind], ['last_error' => ParameterType::NULL]);
    }

    /**
     * Record a send refused before any request (the destination policy, a
     * name that did not resolve): the last result says so, the failure
     * count is left as it was.
     */
    public function recordRefusal(int $userId, string $kind, string $error, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'last_status' => 'failed',
            'last_attempt_at' => $this->at($now),
            'last_error' => mb_substr($error, 0, self::ERROR_MAX),
        ], ['user_id' => $userId, 'kind' => $kind]);
    }

    /**
     * Record a failed send, and switch the channel off when it is the
     * fifth in a row.
     *
     * @return bool whether this failure switched the channel off
     */
    public function recordFailure(int $userId, string $kind, string $error, DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($userId, $kind, $error, $now): bool {
            $at = UtcDateTime::toDatabase($now, $connection->getDatabasePlatform());
            $connection->createQueryBuilder()
                ->update(self::TABLE)
                ->set('failures', 'failures + 1')
                ->set('last_status', ':status')
                ->set('last_attempt_at', ':at')
                ->set('last_error', ':error')
                ->where('user_id = :user')
                ->andWhere('kind = :kind')
                ->setParameter('status', 'failed')
                ->setParameter('at', $at)
                ->setParameter('error', mb_substr($error, 0, self::ERROR_MAX))
                ->setParameter('user', $userId, ParameterType::INTEGER)
                ->setParameter('kind', $kind)
                ->executeStatement();

            // Only the update that crosses the limit switches it off, so the user is told once.
            $switched = $connection->createQueryBuilder()
                ->update(self::TABLE)
                ->set('enabled', ':off')
                ->set('switched_off_at', ':at')
                ->where('user_id = :user')
                ->andWhere('kind = :kind')
                ->andWhere('enabled = :on')
                ->andWhere('failures >= :limit')
                ->setParameter('off', false, ParameterType::BOOLEAN)
                ->setParameter('on', true, ParameterType::BOOLEAN)
                ->setParameter('at', $at)
                ->setParameter('limit', ChannelRecord::SWITCH_OFF_AFTER, ParameterType::INTEGER)
                ->setParameter('user', $userId, ParameterType::INTEGER)
                ->setParameter('kind', $kind)
                ->executeStatement();

            return $switched > 0;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ChannelRecord
    {
        $platform = $this->connection->getDatabasePlatform();
        $decoded = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;
        $settings = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name)) {
                $settings[$name] = $value;
            }
        }
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? UtcDateTime::fromDatabase($value, $platform)
            : null;

        return new ChannelRecord(
            $int($row['id'] ?? null),
            $int($row['user_id'] ?? null),
            is_string($row['kind'] ?? null) ? $row['kind'] : '',
            in_array($row['enabled'] ?? null, [true, 1, '1', 't', 'true'], true),
            $settings,
            is_string($row['last_status'] ?? null) ? $row['last_status'] : null,
            $date($row['last_attempt_at'] ?? null),
            is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            $int($row['failures'] ?? null),
            $date($row['switched_off_at'] ?? null),
        );
    }

    private function at(DateTimeImmutable $now): string
    {
        return UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
    }
}
