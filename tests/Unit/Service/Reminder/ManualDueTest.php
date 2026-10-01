<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\ReminderRules;
use PHPUnit\Framework\TestCase;

/**
 * Manual reminders due on a date, at an odometer, or both (spec.md §7.6,
 * Phase 26.4): whichever comes first.
 */
final class ManualDueTest extends TestCase
{
    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    public function testADateOnlyReminderIsJudgedByItsDateAsBefore(): void
    {
        $due = ReminderRules::manual(self::day('2026-10-05'), null, self::day('2026-10-01'), 7);

        self::assertSame(ReminderStatus::Due, $due->status);
        self::assertEquals(self::day('2026-10-05'), $due->on);
        self::assertFalse($due->projected);
    }

    public function testAnOdometerIsPlacedOnTheCalendarFromTheUsualMileage(): void
    {
        // 50 km a day, 5,000 km to go: 100 days.
        $due = ReminderRules::manual(null, '25000', self::day('2026-10-01'), 7, '20000', 50.0);

        self::assertSame(ReminderStatus::Upcoming, $due->status);
        self::assertEquals(self::day('2027-01-09'), $due->on);
        self::assertTrue($due->projected);
    }

    public function testWithinTheLeadDistanceIsDue(): void
    {
        $due = ReminderRules::manual(null, '25000', self::day('2026-10-01'), 7, '24500', null, '1000');

        self::assertSame(ReminderStatus::Due, $due->status);
        self::assertNull($due->on, 'no projection without enough history');
    }

    public function testPastTheOdometerIsOverdueBeforeTheDate(): void
    {
        $due = ReminderRules::manual(self::day('2027-06-01'), '25000', self::day('2026-10-01'), 7, '25010', 50.0);

        self::assertSame(ReminderStatus::Overdue, $due->status);
    }

    public function testTheSoonerOfTheDateAndTheProjectionApplies(): void
    {
        $due = ReminderRules::manual(self::day('2026-11-01'), '25000', self::day('2026-10-01'), 7, '20000', 50.0);

        self::assertEquals(self::day('2026-11-01'), $due->on);
        self::assertFalse($due->projected);
    }

    public function testAnOdometerWithNoReadingYetIsUpcoming(): void
    {
        $due = ReminderRules::manual(null, '25000', self::day('2026-10-01'), 7);

        self::assertSame(ReminderStatus::Upcoming, $due->status);
        self::assertNull($due->on);
    }
}
