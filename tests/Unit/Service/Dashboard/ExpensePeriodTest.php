<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Dashboard;

use DateTimeImmutable;
use Logbook\Service\Dashboard\ExpensePeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The expense breakdown's `?expenses=` choice (spec.md §7.8): Reports'
 * presets of the same names, the last 12 months by default.
 */
final class ExpensePeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ReportRange, 2: string}>
     */
    public static function valid(): iterable
    {
        yield 'this month' => ['this_month', ReportRange::ThisMonth, '2026-09-01'];
        yield 'last 12 months' => ['last_12_months', ReportRange::TwelveMonths, '2025-10-01'];
        yield 'this year' => ['this_year', ReportRange::ThisYear, '2026-01-01'];
    }

    #[DataProvider('valid')]
    public function testEachChoiceIsTheReportsPresetOfTheSameName(string $value, ReportRange $range, string $from): void
    {
        $period = ExpensePeriod::chosen($value);

        self::assertSame($value, $period->value);
        self::assertSame($range, $period->range());
        $dates = $period->period(self::date('2026-09-27'));
        self::assertSame($from, $dates->from?->format('Y-m-d'));
        self::assertSame('2026-09-27', $dates->to->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'unknown' => ['all_time'];
        yield "Reports' own value" => ['ytd'];
        yield 'different case' => ['This_Month'];
        yield 'repeated (an array)' => [['this_month', 'this_year']];
        yield 'a number' => [12];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsTheLastTwelveMonths(mixed $value): void
    {
        self::assertSame(ExpensePeriod::TwelveMonths, ExpensePeriod::chosen($value));
    }

    public function testOnlyAChosenPeriodGoesInTheUrl(): void
    {
        self::assertSame([], ExpensePeriod::TwelveMonths->toQuery());
        self::assertSame(['expenses' => 'this_month'], ExpensePeriod::ThisMonth->toQuery());
        self::assertSame(['expenses' => 'this_year'], ExpensePeriod::ThisYear->toQuery());
    }

    private static function date(string $date): DateTimeImmutable
    {
        $parsed = LocalTime::parseDate($date);
        self::assertNotNull($parsed);

        return $parsed;
    }
}
