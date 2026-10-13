<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * One line of what a share allows (spec.md §7.21 *What a share allows*):
 * its message key under `share_summary.lines`, the words it needs, and
 * whether it is a *Can* or a *Can't*.
 */
final readonly class ShareSummaryLine
{
    /**
     * @param array<string, string> $params ICU select values; the reader (`person`) is added when shown
     * @param string|null $within the wider line this one is part of (own entries' amounts within costs)
     * @param bool $access false for a line that isn't access (getting reminders)
     */
    public function __construct(
        public string $key,
        public bool $can,
        public array $params = [],
        public ?string $within = null,
        public bool $access = true,
    ) {
    }
}
