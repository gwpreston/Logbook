<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Report\DistanceSpan;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * A period's distance found by bisection over readings in order (Phase
 * 54.5, K149) is the one a scan of every reading found: the same readings
 * kept after the purchase, the same two readings and distance, for periods
 * of any length across clock changes, in London and Auckland, and readings
 * given out of order still scanned.
 */
final class PeriodDistanceBisectTest extends TestCase
{
    public function testEverySpanIsTheOneTheScanFound(): void
    {
        mt_srand(545);
        foreach (['Europe/London', 'Pacific/Auckland', 'UTC'] as $name) {
            $zone = new DateTimeZone($name);
            $readings = self::readings(300);
            foreach ([null, '2020-06-15', '2021-03-28', '2019-01-01'] as $bought) {
                $purchased = $bought === null ? null : LocalTime::parseCalendarDate($bought);
                $owned = PeriodDistance::owned($readings, $purchased, $zone);
                self::assertEquals(self::ownedByScan($readings, $purchased, $zone), $owned);
                for ($i = 0; $i < 60; $i++) {
                    $period = self::period();
                    $label = sprintf('%s %s %s–%s', $name, $bought, $period->from?->format('Ymd'), $period->to->format('Ymd'));
                    $span = PeriodDistance::span($readings, $period, $zone, $purchased);
                    self::assertEquals(self::spanByScan($readings, $period, $zone, $purchased), $span, $label);
                }
            }
        }
    }

    public function testReadingsOutOfOrderAreStillScanned(): void
    {
        $zone = new DateTimeZone('Europe/London');
        $readings = self::readings(40);
        $shuffled = $readings;
        shuffle($shuffled);
        $to = LocalTime::parseCalendarDate('2020-09-30');
        self::assertNotNull($to);
        $period = new ReportPeriod(ReportRange::Custom, LocalTime::parseCalendarDate('2020-03-01'), $to);
        $bought = LocalTime::parseCalendarDate('2020-05-01');

        $span = PeriodDistance::span($shuffled, $period, $zone, null);
        self::assertEquals(self::spanByScan($shuffled, $period, $zone, null), $span);
        self::assertNotNull($span);
        self::assertEquals(self::ownedByScan($shuffled, $bought, $zone), PeriodDistance::owned($shuffled, $bought, $zone));
    }

    /**
     * Readings oldest first, a few hours to a few weeks apart from 2019,
     * some on the same instant, around midnight and the clock changes.
     *
     * @return list<OdometerReading>
     */
    private static function readings(int $count): array
    {
        $at = new DateTimeImmutable('2019-03-30 22:30:00', new DateTimeZone('UTC'));
        $km = 10000;
        $readings = [];
        for ($i = 1; $i <= $count; $i++) {
            $at = $at->modify(sprintf('+%d minutes', mt_rand(0, 9) === 0 ? 0 : mt_rand(60, 60 * 24 * 21)));
            $km += mt_rand(0, 9) === 0 ? -5 : mt_rand(0, 900);
            $reading = $km . '.' . mt_rand(100, 999);
            $readings[] = new OdometerReading($i, 1, $reading, $at, OdometerSource::Manual, null, null, $at, $at);
        }

        return $readings;
    }

    private static function period(): ReportPeriod
    {
        $start = new DateTimeImmutable('2019-01-01', new DateTimeZone('UTC'));
        $from = $start->modify(sprintf('+%d days', mt_rand(0, 2400)));
        $to = $from->modify(sprintf('+%d days', mt_rand(-5, 400)));
        $calendar = static fn (DateTimeImmutable $d): DateTimeImmutable
            => LocalTime::parseCalendarDate($d->format('Y-m-d')) ?? $d;

        return new ReportPeriod(ReportRange::Custom, mt_rand(0, 7) === 0 ? null : $calendar($from), $calendar($to));
    }

    /**
     * owned() as it was before Phase 54.5.
     *
     * @param list<OdometerReading> $readings
     * @return list<OdometerReading>
     */
    private static function ownedByScan(array $readings, ?DateTimeImmutable $purchased, DateTimeZone $zone): array
    {
        if ($purchased === null) {
            return $readings;
        }
        $from = $purchased->format('Y-m-d');

        return array_values(array_filter(
            $readings,
            static fn (OdometerReading $r): bool => PeriodDistance::day($r->recordedAt, $zone)->format('Y-m-d') >= $from,
        ));
    }

    /**
     * span() as it was before Phase 54.5.
     *
     * @param list<OdometerReading> $readings
     */
    private static function spanByScan(
        array $readings,
        ReportPeriod $period,
        DateTimeZone $zone,
        ?DateTimeImmutable $purchased,
    ): ?DistanceSpan {
        $before = null;
        $first = null;
        $last = null;
        foreach (self::ownedByScan($readings, $purchased, $zone) as $reading) {
            $day = PeriodDistance::day($reading->recordedAt, $zone);
            if ($period->from !== null && $day < $period->from) {
                $before = $reading;
            } elseif ($day <= $period->to) {
                $first ??= $reading;
                $last = $reading;
            }
        }
        $start = $before ?? $first;
        if ($start === null || $last === null || $start === $last) {
            return null;
        }
        $distance = Decimal::subtract($last->readingKm, $start->readingKm);
        if (Decimal::compare($distance, '0') <= 0) {
            return null;
        }

        return new DistanceSpan(
            PeriodDistance::day($start->recordedAt, $zone),
            $start->readingKm,
            PeriodDistance::day($last->recordedAt, $zone),
            $last->readingKm,
            $distance,
        );
    }
}
