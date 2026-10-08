<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Webhook\WebhookDelivery;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Support\Database\UtcDateTime;

/**
 * Queued entry-webhook deliveries (`webhook_deliveries`, spec.md §6
 * WebhookDelivery): written by the event recorder in the transaction that
 * changes the entry, sent and retried by the `webhooks` job, removed 7 days
 * after they were queued.
 */
final readonly class WebhookDeliveryRepository
{
    private const string TABLE = 'webhook_deliveries';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function insert(int $webhookId, WebhookEvent $event, array $payload, DateTimeImmutable $now): void
    {
        $at = $this->at($now);
        $this->connection->insert(self::TABLE, [
            'webhook_id' => $webhookId,
            'event' => $event->value,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'next_attempt_at' => $at,
            'created_at' => $at,
        ]);
    }

    /**
     * What is due now, oldest first: on webhooks that are running and have
     * a secret, of users who are not disabled. A paused webhook's
     * deliveries wait (#293).
     *
     * @return list<WebhookDelivery>
     */
    public function due(DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('d.*')
            ->from(self::TABLE, 'd')
            ->innerJoin('d', 'webhooks', 'w', 'w.id = d.webhook_id')
            ->innerJoin('w', 'users', 'u', 'u.id = w.user_id')
            ->where('d.next_attempt_at IS NOT NULL', 'd.next_attempt_at <= :now')
            ->andWhere('w.paused = :off', 'w.secret IS NOT NULL', 'u.disabled_at IS NULL')
            ->setParameter('now', $this->at($now))
            ->setParameter('off', false, ParameterType::BOOLEAN)
            ->orderBy('d.next_attempt_at')
            ->addOrderBy('d.id')
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @return list<WebhookDelivery> the webhook's, newest first (tests and the page's count)
     */
    public function listForWebhook(int $webhookId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('webhook_id = :webhook')
            ->setParameter('webhook', $webhookId, ParameterType::INTEGER)
            ->orderBy('id', 'DESC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Deliveries still to go, per webhook (the Webhooks page).
     *
     * @param list<int> $webhookIds
     * @return array<int, int> webhook id → waiting
     */
    public function waiting(array $webhookIds): array
    {
        if ($webhookIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('webhook_id', 'COUNT(*) AS n')
            ->from(self::TABLE)
            ->where('webhook_id IN (:ids)', 'next_attempt_at IS NOT NULL')
            ->setParameter('ids', $webhookIds, ArrayParameterType::INTEGER)
            ->groupBy('webhook_id')
            ->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) (is_numeric($row['webhook_id'] ?? null) ? $row['webhook_id'] : 0)]
                = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
        }

        return $counts;
    }

    public function markDelivered(int $id, int $attempts, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['attempts' => $attempts, 'next_attempt_at' => null, 'delivered_at' => $this->at($now)],
            ['id' => $id],
            ['next_attempt_at' => ParameterType::NULL],
        );
    }

    /**
     * A failed attempt: tried again at $next, or given up when null.
     */
    public function markFailed(int $id, int $attempts, ?DateTimeImmutable $next): void
    {
        $this->connection->update(
            self::TABLE,
            ['attempts' => $attempts, 'next_attempt_at' => $next === null ? null : $this->at($next)],
            ['id' => $id],
            ['next_attempt_at' => $next === null ? ParameterType::NULL : ParameterType::STRING],
        );
    }

    /**
     * Remove every row queued before $cutoff, delivered, given up or still
     * waiting (spec.md §7.20: 7 days).
     *
     * @return int rows removed
     */
    public function deleteQueuedBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('created_at < :cutoff')
            ->setParameter('cutoff', $this->at($cutoff))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WebhookDelivery
    {
        $platform = $this->connection->getDatabasePlatform();
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? UtcDateTime::fromDatabase($value, $platform)
            : null;
        $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
        $decoded = [];
        foreach (is_array($payload) ? $payload : [] as $key => $value) {
            if (is_string($key)) {
                $decoded[$key] = $value;
            }
        }

        return new WebhookDelivery(
            $int($row['id'] ?? null),
            $int($row['webhook_id'] ?? null),
            WebhookEvent::tryFrom(is_string($row['event'] ?? null) ? $row['event'] : '') ?? WebhookEvent::EntryUpdated,
            $decoded,
            $int($row['attempts'] ?? null),
            $date($row['next_attempt_at'] ?? null),
            $date($row['delivered_at'] ?? null),
            $date($row['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
        );
    }

    private function at(DateTimeImmutable $now): string
    {
        return UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
    }
}
