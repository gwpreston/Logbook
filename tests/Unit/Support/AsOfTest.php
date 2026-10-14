<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use DateTimeImmutable;
use Logbook\Support\AsOf;
use Logbook\Support\AsOfKind;
use PHPUnit\Framework\TestCase;

final class AsOfTest extends TestCase
{
    public function testTodayIsNotShown(): void
    {
        $asOf = AsOf::of(AsOfKind::Valuation, self::day('2026-10-13'), self::day('2026-10-13'), null, 12);

        self::assertFalse($asOf->shows());
        self::assertSame(0, $asOf->ageDays);
        self::assertFalse($asOf->stale);
    }

    public function testAnOlderDateIsShownWithItsAge(): void
    {
        $asOf = AsOf::of(AsOfKind::Valuation, self::day('2026-07-13'), self::day('2026-10-13'));

        self::assertTrue($asOf->shows());
        self::assertSame(92, $asOf->ageDays);
        self::assertSame(3, $asOf->ageMonths);
        self::assertFalse($asOf->stale, 'no threshold, so never stale');
    }

    public function testStaleOnlyPastTheThreshold(): void
    {
        $valued = self::day('2025-10-13');

        self::assertFalse(AsOf::of(AsOfKind::Valuation, $valued, self::day('2026-10-13'), null, 12)->stale, 'exactly 12 months');
        self::assertTrue(AsOf::of(AsOfKind::Valuation, $valued, self::day('2026-10-14'), null, 12)->stale);
        self::assertFalse(
            AsOf::of(AsOfKind::Valuation, $valued, self::day('2026-10-14'), null, 24)->stale,
            "the owner's threshold",
        );
    }

    public function testADateInTheFutureHasNoAgeAndIsShown(): void
    {
        $asOf = AsOf::of(AsOfKind::Quote, self::day('2026-11-01'), self::day('2026-10-13'), null, 1);

        self::assertTrue($asOf->shows());
        self::assertSame(0, $asOf->ageDays);
        self::assertFalse($asOf->stale);
    }

    public function testOldestOfSeveralIgnoresNulls(): void
    {
        $today = self::day('2026-10-13');
        $new = AsOf::of(AsOfKind::Valuation, self::day('2026-09-01'), $today);
        $old = AsOf::of(AsOfKind::LastTime, self::day('2025-01-01'), $today);

        self::assertSame($old, AsOf::oldest([$new, null, $old]));
        self::assertNull(AsOf::oldest([]));
        self::assertNull(AsOf::oldest([null]));
    }

    public function testToArrayIsTheApiShape(): void
    {
        $asOf = AsOf::of(AsOfKind::Measurement, self::day('2026-03-02'), self::day('2026-10-13'), 'the last 12 months', 6);

        self::assertSame(
            ['date' => '2026-03-02', 'kind' => 'measurement', 'basis' => 'the last 12 months', 'stale' => true],
            $asOf->toArray(),
        );
        self::assertSame('as_of.kind.measurement', $asOf->kind->labelKey());
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
    }
}
