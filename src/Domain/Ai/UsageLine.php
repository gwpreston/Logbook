<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * One line of Settings → AI's usage this month (spec.md §7.25 *Usage
 * log*): a connection's or a task's calls, tokens and failures.
 */
final readonly class UsageLine
{
    public function __construct(
        /** The connection id or the task value; null for a deleted connection. */
        public int|string|null $key,
        public int $calls,
        public int $tokensIn,
        public int $tokensOut,
        public int $failures,
    ) {
    }

    public function tokens(): int
    {
        return $this->tokensIn + $this->tokensOut;
    }
}
