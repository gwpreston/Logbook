<?php

declare(strict_types=1);

namespace Logbook\Domain\Webhook;

use DateTimeImmutable;

/**
 * One queued call to a webhook (spec.md §6 WebhookDelivery): ids and links
 * only, never an entry's contents (#285).
 */
final readonly class WebhookDelivery
{
    /**
     * After a failed attempt, the wait before the next (#288): 1 minute, 5
     * minutes, 30 minutes, 2 hours, 6 hours, then given up. Minimums: the
     * job runs every pass (#291).
     */
    public const array RETRY_AFTER = [60, 300, 1800, 7200, 21600];

    /** Days a row is kept after it was queued. */
    public const int KEEP_DAYS = 7;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $id,
        public int $webhookId,
        public WebhookEvent $event,
        public array $payload,
        public int $attempts,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?DateTimeImmutable $deliveredAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * When to try again after the attempt numbered $attempts (1 for the
     * first) failed, or null once every retry is spent.
     */
    public static function retryAt(int $attempts, DateTimeImmutable $failedAt): ?DateTimeImmutable
    {
        $wait = self::RETRY_AFTER[$attempts - 1] ?? null;

        return $wait === null ? null : $failedAt->modify('+' . $wait . ' seconds');
    }
}
