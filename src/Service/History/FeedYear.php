<?php

declare(strict_types=1);

namespace Logbook\Service\History;

/**
 * One year page of the activity feed (spec.md §7.16) and where it sits: the
 * first and newest years with anything of the chosen kinds, and the nearest
 * such years after and before it (empty years are skipped). With nothing at
 * all, every year is null.
 */
final readonly class FeedYear
{
    /**
     * @param list<ActivityItem> $items newest first
     */
    public function __construct(
        public ?int $year,
        public array $items = [],
        public ?int $newer = null,
        public ?int $older = null,
        public ?int $first = null,
        public ?int $newest = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->year === null;
    }
}
