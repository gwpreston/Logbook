<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Service\Report\PeriodDistance;
use PHPUnit\Framework\TestCase;

/**
 * The owner's day of a reading is remembered per instant (a page asks it for
 * many periods): it must still depend on the zone it is asked in (K149).
 */
final class PeriodDistanceDayTest extends TestCase
{
    public function testTheSameInstantIsADifferentDayInAnotherZone(): void
    {
        // 23:30 UTC on 1 June is 00:30 on the 2nd in London (BST) and still the 1st in UTC.
        $instant = new DateTimeImmutable('2026-06-01T23:30:00Z');

        $utc = PeriodDistance::day($instant, new DateTimeZone('UTC'));
        $london = PeriodDistance::day($instant, new DateTimeZone('Europe/London'));
        $newYork = PeriodDistance::day($instant, new DateTimeZone('America/New_York'));

        self::assertSame('2026-06-01', $utc->format('Y-m-d'));
        self::assertSame('2026-06-02', $london->format('Y-m-d'));
        self::assertSame('2026-06-01', $newYork->format('Y-m-d'));
        // Asked again, in the other order: each zone still gets its own day.
        self::assertSame('2026-06-02', PeriodDistance::day($instant, new DateTimeZone('Europe/London'))->format('Y-m-d'));
        self::assertSame('2026-06-01', PeriodDistance::day($instant, new DateTimeZone('UTC'))->format('Y-m-d'));
    }

    public function testADayIsAMidnightCalendarDate(): void
    {
        $day = PeriodDistance::day(new DateTimeImmutable('2026-01-15T10:15:00Z'), new DateTimeZone('Europe/London'));

        self::assertSame('2026-01-15 00:00:00', $day->format('Y-m-d H:i:s'));
    }
}
