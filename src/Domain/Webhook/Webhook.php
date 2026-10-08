<?php

declare(strict_types=1);

namespace Logbook\Domain\Webhook;

use DateTimeImmutable;

/**
 * A user's entry webhook (spec.md §6 Webhook, §7.20 *Webhooks*). The secret
 * is stored sealed and never leaves the server after it is shown once.
 */
final readonly class Webhook
{
    /** Consecutive failed attempts that pause a webhook (#288, #293). */
    public const int PAUSE_AFTER = 50;

    /**
     * @param list<WebhookEvent>|null $events null for every event
     */
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public string $url,
        public ?array $events,
        /** Sealed (SecretBox, info `logbook-webhook`); null after a restore until a new one is made. */
        public ?string $secret,
        public ?WebhookPause $pausedReason,
        public ?string $lastStatus,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?string $lastError,
        public int $failures,
        public bool $noticePending,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPaused(): bool
    {
        return $this->pausedReason !== null;
    }

    public function needsSecret(): bool
    {
        return $this->secret === null;
    }

    public function receives(WebhookEvent $event): bool
    {
        return $this->events === null || in_array($event, $this->events, true);
    }
}
