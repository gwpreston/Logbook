<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\View\LineChart;
use PHPUnit\Framework\TestCase;

/**
 * A line chart's figures as table rows (spec.md §8 *Printing reports*).
 */
final class LineChartTest extends TestCase
{
    public function testRowsMergeSeriesByTimeNewestFirst(): void
    {
        $chart = (new LineChart(DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'), 'Price per L', 3, 'GBP'))
            ->addSeries('E10', [[self::at('2026-09-01T08:00:00Z'), 1.459], [self::at('2026-09-20T08:00:00Z'), 1.449]])
            ->addSeries('E5', [[self::at('2026-09-10T08:00:00Z'), 1.589], [self::at('2026-09-20T08:00:00Z'), 1.579]]);

        self::assertSame(['E10', 'E5'], $chart->seriesLabels());
        self::assertSame('Price per L', $chart->unitLabel());
        self::assertSame(3, $chart->decimals());
        self::assertSame('GBP', $chart->currency());
        self::assertFalse($chart->hasCalendarDates());

        $rows = $chart->rows();
        self::assertCount(3, $rows);
        self::assertSame('2026-09-20T08:00:00+00:00', $rows[0]->at->format(DATE_ATOM));
        self::assertSame([1.449, 1.579], $rows[0]->values);
        self::assertSame([null, 1.589], $rows[1]->values, 'a series with no point then');
        self::assertSame([1.459, null], $rows[2]->values);
        self::assertSame('UTC', $rows[2]->at->getTimezone()->getName());
    }

    public function testAnEmptyChartHasNoRows(): void
    {
        $chart = new LineChart(DisplayPreferences::defaults('en', 'UTC', 'EUR'), 'km', 0);

        self::assertSame([], $chart->rows());
        self::assertSame([], $chart->seriesLabels());
    }

    private static function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
