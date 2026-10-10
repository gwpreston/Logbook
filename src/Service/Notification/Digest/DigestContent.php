<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

/**
 * What Phase 43 adds to the monthly digest (spec.md §7.11 *The monthly
 * briefing*), for the sections the user includes: open issues, last month
 * and insights. Each is empty when not included.
 */
final readonly class DigestContent
{
    /**
     * @param list<OpenIssues> $issues
     * @param list<DigestInsight> $insights
     */
    public function __construct(
        public array $issues = [],
        public ?LastMonth $lastMonth = null,
        public array $insights = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->issues === [] && $this->lastMonth === null && $this->insights === [];
    }
}
