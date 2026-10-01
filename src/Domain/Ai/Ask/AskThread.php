<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

use DateTimeImmutable;

/**
 * One Ask Logbook conversation (spec.md §6 AiThread).
 */
final readonly class AskThread
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        public DateTimeImmutable $createdAt,
        /** The last message; retention counts from it. */
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
