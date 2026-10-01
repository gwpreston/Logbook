<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

use DateTimeImmutable;

/**
 * A row for the usage log (spec.md §6 AiRequest). Content only with
 * `AI_LOG_CONTENT=true`.
 */
final readonly class RequestRecord
{
    public function __construct(
        public ?int $userId,
        /** An AiTaskName value or `test`. */
        public string $task,
        public ?int $connectionId,
        public string $model,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public int $durationMs,
        public Outcome $outcome,
        public ?string $errorCode,
        public ?string $content,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
