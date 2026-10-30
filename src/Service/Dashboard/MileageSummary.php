<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * The mileage widget's figures (spec.md §7.8), in kilometres: this calendar
 * month and year so far (a report's distance driven, §7.7) and the monthly
 * average of the Mileage tab (§7.2). For several vehicles each figure is
 * worked out per vehicle and added up, so readings of different vehicles are
 * never subtracted from each other. A figure no vehicle has the history for
 * is null ("—"), not 0.
 */
final readonly class MileageSummary
{
    /** Bars of the chart: this month and the 11 before it. */
    public const int CHART_MONTHS = 12;

    /**
     * @param list<MonthDistance> $months the last 12 calendar months, oldest first
     */
    public function __construct(
        public ?string $thisMonthKm,
        public ?string $thisYearKm,
        public ?float $monthlyAverageKm,
        public array $months = [],
    ) {
    }

    public function hasMonths(): bool
    {
        foreach ($this->months as $month) {
            if ($month->km !== null && Decimal::compare($month->km, '0') > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<OdometerReading>> $readings each vehicle's readings, oldest first
     * @param list<DateTimeImmutable|null> $purchased each vehicle's purchase date, in the same order: readings before it are
     *                                                an earlier owner's and stay out of the distances. The average
     *                                                per month is the Mileage tab's figure, from all the readings (§7.8).
     * @param DateTimeImmutable $today the owner's calendar date
     * @param list<OdometerHistory>|null $histories each vehicle's history of those readings, when the page has
     *                                             worked them out already (Phase 54.5)
     */
    public static function of(
        array $readings,
        DateTimeImmutable $today,
        DateTimeZone $zone,
        array $purchased,
        ?array $histories = null,
    ): self {
        $month = ReportPeriod::preset(ReportRange::ThisMonth, $today);
        $year = ReportPeriod::preset(ReportRange::ThisYear, $today);

        $firstOfMonth = $month->from ?? $today;
        $chart = [];
        for ($back = self::CHART_MONTHS - 1; $back >= 0; $back--) {
            $first = LocalTime::addMonths($firstOfMonth, -$back);
            $last = LocalTime::addMonths($first, 1)->modify('-1 day');
            $chart[] = new ReportPeriod(ReportRange::Custom, $first, $last < $today ? $last : $today);
        }

        $thisMonth = null;
        $thisYear = null;
        $average = null;
        $months = array_fill(0, count($chart), null);
        foreach ($readings as $i => $vehicleReadings) {
            $bought = $purchased[$i] ?? null;
            $thisMonth = self::add($thisMonth, self::driven($vehicleReadings, $month, $zone, $bought));
            $thisYear = self::add($thisYear, self::driven($vehicleReadings, $year, $zone, $bought));
            $perMonth = ($histories[$i] ?? new OdometerHistory($vehicleReadings))->averageKmPerMonth();
            if ($perMonth !== null) {
                $average = ($average ?? 0.0) + $perMonth;
            }
            foreach ($chart as $m => $period) {
                $months[$m] = self::add($months[$m], self::driven($vehicleReadings, $period, $zone, $bought));
            }
        }

        return new self(
            $thisMonth,
            $thisYear,
            $average,
            array_map(
                static fn (ReportPeriod $p, ?string $km): MonthDistance => new MonthDistance($p->from ?? $p->to, $km),
                $chart,
                $months,
            ),
        );
    }

    /**
     * Kilometres driven in the period: null without a reading on or before
     * its last day (no history yet), else the report's distance driven, or
     * 0 when nothing was driven.
     *
     * @param list<OdometerReading> $readings
     */
    private static function driven(
        array $readings,
        ReportPeriod $period,
        DateTimeZone $zone,
        ?DateTimeImmutable $purchased,
    ): ?string {
        $owned = PeriodDistance::owned($readings, $purchased, $zone);
        // Any reading by the period's end: the oldest first, so usually the first one says (Phase 54.5).
        $known = false;
        foreach ($owned as $reading) {
            if (PeriodDistance::day($reading->recordedAt, $zone) <= $period->to) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return null;
        }

        return PeriodDistance::km($owned, $period, $zone, $purchased) ?? '0';
    }

    private static function add(?string $total, ?string $km): ?string
    {
        if ($km === null) {
            return $total;
        }

        return Decimal::add($total ?? '0', $km);
    }
}
