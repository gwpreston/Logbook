<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Odometer;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Service\Odometer\OdometerPlausibility;
use Logbook\Service\Odometer\OdometerWarning;
use PHPUnit\Framework\TestCase;

final class OdometerPlausibilityTest extends TestCase
{
    public function testNormalReadingsAreFine(): void
    {
        self::assertSame([], OdometerPlausibility::check([
            self::reading(1, '10000', '2026-01-01 09:00'),
            self::reading(2, '10350', '2026-01-08 09:00'),
            self::reading(3, '10350', '2026-01-08 18:00'),  // parked all day
            self::reading(4, '12100', '2026-01-09 18:00'),  // a long drive: 1,750 km in a day
        ]));
    }

    public function testGoingBackwardsIsFlagged(): void
    {
        $warnings = OdometerPlausibility::check([
            self::reading(1, '10000', '2026-01-01 09:00'),
            self::reading(2, '9990', '2026-01-08 09:00'),
            self::reading(3, '10400', '2026-01-15 09:00'),
        ]);

        self::assertSame([2], array_keys($warnings));
        self::assertSame(OdometerWarning::BACKWARDS, $warnings[2]->type);
        self::assertTrue($warnings[2]->isBackwards());
        self::assertSame(1, $warnings[2]->previous->id);
        self::assertSame('-10.000', $warnings[2]->distanceKm);
    }

    public function testAnImplausibleJumpIsFlagged(): void
    {
        // A slipped digit: 10,350 typed as 103,500.
        $warnings = OdometerPlausibility::check([
            self::reading(1, '10000', '2026-01-01 09:00'),
            self::reading(2, '103500', '2026-01-08 09:00'),
            self::reading(3, '103900', '2026-01-15 09:00'),
        ]);

        self::assertSame([2], array_keys($warnings));
        self::assertSame(OdometerWarning::JUMP, $warnings[2]->type);
        self::assertEqualsWithDelta(7.0, $warnings[2]->days, 1e-9);
    }

    public function testShortIntervalsCountAsAtLeastOneDay(): void
    {
        $warnings = OdometerPlausibility::check([
            self::reading(1, '10000', '2026-01-01 09:00'),
            self::reading(2, '11900', '2026-01-01 10:00'),  // within a day's plausible driving
            self::reading(3, '14000', '2026-01-01 11:00'),  // 2,100 km since: not plausible
        ]);

        self::assertSame([3], array_keys($warnings));
        self::assertSame(1.0, $warnings[3]->days);
    }

    public function testHistoryFiguresAndWarnings(): void
    {
        $history = new OdometerHistory([
            self::reading(1, '10000', '2026-01-01 09:00'),
            self::reading(2, '9990', '2026-01-02 09:00'),
            self::reading(3, '13043.75', '2026-04-02 09:00'),
        ]);

        self::assertSame(3, $history->latest()?->id);
        self::assertSame(1, $history->first()?->id);
        self::assertSame([3, 2, 1], array_map(static fn (OdometerReading $r): int => $r->id, $history->newestFirst()));
        self::assertSame([2 => '-10.000', 3 => '3053.750'], $history->deltas());
        self::assertSame('3043.750', $history->totalDistanceKm());
        self::assertNotNull($history->warningFor(2));
        self::assertNull($history->warningFor(3));
        // 3,043.75 km over 91 days ≈ 1,018.05 km per average month.
        self::assertEqualsWithDelta(1018.05, $history->averageKmPerMonth() ?? 0.0, 0.01);
    }

    public function testMonthlyAverageNeedsAWeekOfHistory(): void
    {
        self::assertNull((new OdometerHistory([]))->averageKmPerMonth());
        self::assertNull((new OdometerHistory([self::reading(1, '10', '2026-01-01 09:00')]))->averageKmPerMonth());
        self::assertNull((new OdometerHistory([
            self::reading(1, '10', '2026-01-01 09:00'),
            self::reading(2, '500', '2026-01-05 09:00'),
        ]))->averageKmPerMonth());
    }

    private static function reading(int $id, string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        $km .= str_contains($km, '.') ? '0' : '.000';

        return new OdometerReading($id, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }
}
