<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use DateTimeImmutable;

/**
 * One row of a LineChart's table: a point in time (UTC) and each series'
 * value then, already in the user's units; null where a series has no point.
 */
final readonly class LineChartRow
{
    /**
     * @param list<?float> $values one per series, in LineChart::seriesLabels() order
     */
    public function __construct(
        public DateTimeImmutable $at,
        public array $values,
    ) {
    }
}
