<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Webhook\Webhook;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Domain\Webhook\WebhookPause;
use Logbook\Support\Database\UtcDateTime;

/**
 * Users' entry webhooks (`webhooks`, spec.md §6 Webhook). A page reads and
 * changes them by user and id, so one user's webhook is never reached
 * through another's request; the `webhooks` job and the event recorder
 * read across users.
 */
final readonly class WebhookRepository
{
    private const string TABLE = 'webhooks';
    private const int ERROR_MAX = 255;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<Webhook>
     */
    public function listForUser(int $userId): array
    {
        return $this->hydrateAll($this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative());
    }

    /**
     * Every webhook of these users, for the event recorder.
     *
     * @param list<int> $userIds
     * @return list<Webhook>
     */
    public function listForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->hydrateAll($this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id IN (:users)')
            ->setParameter('users', $userIds, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative());
    }

    /**
     * Whether any user has a webhook: the recorder's first, cheap question.
     */
    public function any(): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('1')
            ->from(self::TABLE)
            ->setMaxResults(1)
            ->fetchOne() !== false;
    }

    public function find(int $userId, int $id): ?Webhook
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Any user's webhook by id, for the `webhooks` job only.
     */
    public function findById(int $id): ?Webhook
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Webhooks paused for failures whose user has not been told yet (#294).
     *
     * @return list<Webhook>
     */
    public function noticePending(): array
    {
        return $this->hydrateAll($this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('notice_pending = :on')
            ->setParameter('on', true, ParameterType::BOOLEAN)
            ->orderBy('id')
            ->fetchAllAssociative());
    }

    /**
     * @param list<WebhookEvent> $events
     */
    public function insert(int $userId, string $name, string $url, array $events, string $secret, DateTimeImmutable $now): int
    {
        $at = $this->at($now);
        $stored = WebhookEvent::store($events);
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'name' => $name,
            'url' => $url,
            'events' => $stored,
            'secret' => $secret,
            'paused' => false,
            'failures' => 0,
            'notice_pending' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'events' => $stored === null ? ParameterType::NULL : ParameterType::STRING,
            'paused' => ParameterType::BOOLEAN,
            'notice_pending' => ParameterType::BOOLEAN,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function setSecret(int $userId, int $id, string $secret, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['secret' => $secret, 'updated_at' => $this->at($now)],
            ['user_id' => $userId, 'id' => $id],
        );
    }

    public function pause(int $userId, int $id, WebhookPause $reason, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['paused' => true, 'paused_reason' => $reason->value, 'updated_at' => $this->at($now)],
            ['user_id' => $userId, 'id' => $id],
            ['paused' => ParameterType::BOOLEAN],
        );
    }

    /**
     * Running again, its failures back to 0 (#292).
     */
    public function resume(int $userId, int $id, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            [
                'paused' => false,
                'paused_reason' => null,
                'failures' => 0,
                'notice_pending' => false,
                'updated_at' => $this->at($now),
            ],
            ['user_id' => $userId, 'id' => $id],
            [
                'paused' => ParameterType::BOOLEAN,
                'paused_reason' => ParameterType::NULL,
                'notice_pending' => ParameterType::BOOLEAN,
            ],
        );
    }

    public function delete(int $userId, int $id): void
    {
        $this->connection->delete(self::TABLE, ['user_id' => $userId, 'id' => $id]);
    }

    /**
     * A delivery (or a test) got a 2xx: the failures go back to 0 (#293).
     */
    public function recordSuccess(int $id, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'last_status' => 'ok',
            'last_attempt_at' => $this->at($now),
            'last_error' => null,
            'failures' => 0,
        ], ['id' => $id], ['last_error' => ParameterType::NULL]);
    }

    /**
     * A failed attempt. One that counts adds to the failures and, at the
     * 50th in a row, pauses the webhook for failures with the notice still
     * to send (#293, #294); a refused destination or a test doesn't count.
     *
     * @return bool whether this failure paused the webhook
     */
    public function recordFailure(int $id, string $error, bool $counts, DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $error, $counts, $now): bool {
            $at = UtcDateTime::toDatabase($now, $connection->getDatabasePlatform());
            $update = $connection->createQueryBuilder()
                ->update(self::TABLE)
                ->set('last_status', ':failed')
                ->set('last_attempt_at', ':at')
                ->set('last_error', ':error')
                ->where('id = :id')
                ->setParameter('failed', 'failed')
                ->setParameter('at', $at)
                ->setParameter('error', mb_substr($error, 0, self::ERROR_MAX))
                ->setParameter('id', $id, ParameterType::INTEGER);
            if ($counts) {
                $update->set('failures', 'failures + 1');
            }
            $update->executeStatement();
            if (!$counts) {
                return false;
            }

            return $connection->createQueryBuilder()
                ->update(self::TABLE)
                ->set('paused', ':on')
                ->set('paused_reason', ':reason')
                ->set('notice_pending', ':on')
                ->where('id = :id', 'paused = :off', 'failures >= :limit')
                ->setParameter('on', true, ParameterType::BOOLEAN)
                ->setParameter('off', false, ParameterType::BOOLEAN)
                ->setParameter('reason', WebhookPause::Failures->value)
                ->setParameter('limit', Webhook::PAUSE_AFTER, ParameterType::INTEGER)
                ->setParameter('id', $id, ParameterType::INTEGER)
                ->executeStatement() > 0;
        });
    }

    public function clearNotice(int $id): void
    {
        $this->connection->update(
            self::TABLE,
            ['notice_pending' => false],
            ['id' => $id],
            ['notice_pending' => ParameterType::BOOLEAN],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Webhook>
     */
    private function hydrateAll(array $rows): array
    {
        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Webhook
    {
        $platform = $this->connection->getDatabasePlatform();
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? UtcDateTime::fromDatabase($value, $platform)
            : null;
        $truthy = static fn (mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true'], true);
        $paused = $truthy($row['paused'] ?? null);

        return new Webhook(
            $int($row['id'] ?? null),
            $int($row['user_id'] ?? null),
            (string) $text($row['name'] ?? null),
            (string) $text($row['url'] ?? null),
            WebhookEvent::listFrom($text($row['events'] ?? null)),
            $text($row['secret'] ?? null),
            $paused ? (WebhookPause::tryFrom((string) $text($row['paused_reason'] ?? null)) ?? WebhookPause::User) : null,
            $text($row['last_status'] ?? null),
            $date($row['last_attempt_at'] ?? null),
            $text($row['last_error'] ?? null),
            $int($row['failures'] ?? null),
            $truthy($row['notice_pending'] ?? null),
            $date($row['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            $date($row['updated_at'] ?? null) ?? new DateTimeImmutable('@0'),
        );
    }

    private function at(DateTimeImmutable $now): string
    {
        return UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
    }
}
