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
    public function __construct(
        public ?string $thisMonthKm,
        public ?string $thisYearKm,
        public ?float $monthlyAverageKm,
    ) {
    }

    /**
     * @param list<list<OdometerReading>> $readings each vehicle's readings, oldest first
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function of(array $readings, DateTimeImmutable $today, DateTimeZone $zone): self
    {
        $month = ReportPeriod::preset(ReportRange::ThisMonth, $today);
        $year = ReportPeriod::preset(ReportRange::ThisYear, $today);

        $thisMonth = null;
        $thisYear = null;
        $average = null;
        foreach ($readings as $vehicleReadings) {
            $thisMonth = self::add($thisMonth, self::driven($vehicleReadings, $month, $zone));
            $thisYear = self::add($thisYear, self::driven($vehicleReadings, $year, $zone));
            $perMonth = (new OdometerHistory($vehicleReadings))->averageKmPerMonth();
            if ($perMonth !== null) {
                $average = ($average ?? 0.0) + $perMonth;
            }
        }

        return new self($thisMonth, $thisYear, $average);
    }

    /**
     * Kilometres driven in the period: null without a reading on or before
     * its last day (no history yet), else the report's distance driven, or
     * 0 when nothing was driven.
     *
     * @param list<OdometerReading> $readings
     */
    private static function driven(array $readings, ReportPeriod $period, DateTimeZone $zone): ?string
    {
        $known = array_filter(
            $readings,
            static fn (OdometerReading $r): bool => LocalTime::dateOf($r->recordedAt, $zone) <= $period->to,
        );
        if ($known === []) {
            return null;
        }

        return PeriodDistance::km($readings, $period, $zone) ?? '0';
    }

    private static function add(?string $total, ?string $km): ?string
    {
        if ($km === null) {
            return $total;
        }

        return Decimal::add($total ?? '0', $km);
    }
}
