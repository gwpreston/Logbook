<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use DateTimeImmutable;

/**
 * One month subheading of a History year page and its rows, newest first.
 */
final readonly class HistoryMonth
{
    /**
     * @param DateTimeImmutable $month its first day
     * @param list<ActivityItem|FillUpRun> $rows
     */
    public function __construct(
        public DateTimeImmutable $month,
        public array $rows,
    ) {
    }
}
