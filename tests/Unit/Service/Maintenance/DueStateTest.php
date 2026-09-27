<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Service\Maintenance\DueTrigger;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * "Whichever comes first": a next-due point judged against today and the
 * current odometer.
 */
final class DueStateTest extends TestCase
{
    private const string TODAY = '2026-09-27';

    public function testNothingKnown(): void
    {
        $state = DueState::evaluate(new NextDue(), self::date(self::TODAY), '1000.000', 30.0);

        self::assertSame(DueStatus::Unknown, $state->status);
        self::assertNull($state->trigger);
    }

    public function testMonthsOnly(): void
    {
        $ok = DueState::evaluate(new NextDue(self::date('2027-03-01')), self::date(self::TODAY), null, null);
        self::assertSame(DueStatus::Ok, $ok->status);
        self::assertSame(DueTrigger::Date, $ok->trigger);
        self::assertSame(155, $ok->daysLeft);
        self::assertFalse($ok->projected);

        $soon = DueState::evaluate(new NextDue(self::date('2026-10-20')), self::date(self::TODAY), null, null);
        self::assertSame(DueStatus::Soon, $soon->status);
        self::assertSame(23, $soon->daysLeft);

        $today = DueState::evaluate(new NextDue(self::date(self::TODAY)), self::date(self::TODAY), null, null);
        self::assertSame(DueStatus::Soon, $today->status, 'due today is not yet overdue');
        self::assertSame(0, $today->daysLeft);

        $overdue = DueState::evaluate(new NextDue(self::date('2026-09-20')), self::date(self::TODAY), null, null);
        self::assertSame(DueStatus::Overdue, $overdue->status);
        self::assertSame(-7, $overdue->daysLeft);
    }

    public function testDistanceOnly(): void
    {
        $next = new NextDue(null, '50000.000');

        $ok = DueState::evaluate($next, self::date(self::TODAY), '45000.000', null);
        self::assertSame(DueStatus::Ok, $ok->status);
        self::assertSame(DueTrigger::Distance, $ok->trigger);
        self::assertSame('5000.000', $ok->kmLeft);
        self::assertNull($ok->dueOn, 'no mileage history, so no date');

        $projected = DueState::evaluate($next, self::date(self::TODAY), '45000.000', 50.0);
        self::assertSame('2027-01-05', $projected->dueOn?->format('Y-m-d'), '5,000 km at 50 km a day: 100 days');
        self::assertTrue($projected->projected);
        self::assertSame(DueStatus::Ok, $projected->status);

        $soon = DueState::evaluate($next, self::date(self::TODAY), '49200.000', null);
        self::assertSame(DueStatus::Soon, $soon->status, 'within 1,000 km');

        $overdue = DueState::evaluate($next, self::date(self::TODAY), '50000.500', 50.0);
        self::assertSame(DueStatus::Overdue, $overdue->status);
        self::assertSame('-0.500', $overdue->kmLeft);

        $noOdometer = DueState::evaluate($next, self::date(self::TODAY), null, null);
        self::assertSame(DueStatus::Unknown, $noOdometer->status, 'nothing to measure the distance against');
    }

    public function testBothTheDateComesFirst(): void
    {
        // 5,000 km to go at 20 km a day = 250 days; the date is 155 days away.
        $state = DueState::evaluate(self::both('2027-03-01'), self::today(), '45000.000', 20.0);

        self::assertSame(DueTrigger::Date, $state->trigger);
        self::assertSame('2027-03-01', $state->dueOn?->format('Y-m-d'));
        self::assertFalse($state->projected);
        self::assertSame(DueStatus::Ok, $state->status);
    }

    public function testBothTheDistanceComesFirst(): void
    {
        // 5,000 km to go at 100 km a day = 50 days; the date is 155 days away.
        $state = DueState::evaluate(self::both('2027-03-01'), self::today(), '45000.000', 100.0);

        self::assertSame(DueTrigger::Distance, $state->trigger);
        self::assertSame('2026-11-16', $state->dueOn?->format('Y-m-d'));
        self::assertTrue($state->projected);
        self::assertSame(50, $state->daysLeft);
    }

    public function testBothEitherLimitPassedIsOverdue(): void
    {
        $kmPassed = DueState::evaluate(self::both('2027-03-01'), self::today(), '50100.000', null);
        self::assertSame(DueStatus::Overdue, $kmPassed->status, 'distance reached long before the date');
        self::assertSame(DueTrigger::Distance, $kmPassed->trigger);

        $datePassed = DueState::evaluate(self::both('2026-09-01'), self::today(), '42000.000', 20.0);
        self::assertSame(DueStatus::Overdue, $datePassed->status, 'the date passed with distance to spare');
        self::assertSame(DueTrigger::Date, $datePassed->trigger);
    }

    public function testBothWithoutMileageHistoryTheDistanceStillCountsTowardsSoon(): void
    {
        $state = DueState::evaluate(self::both('2027-03-01'), self::today(), '49500.000', null);

        self::assertSame(DueTrigger::Date, $state->trigger, 'the only limit that can be placed on the calendar');
        self::assertSame(DueStatus::Soon, $state->status, '500 km to go');
    }

    /**
     * Due on $date or at 50,000 km, whichever comes first.
     */
    private static function both(string $date): NextDue
    {
        return new NextDue(self::date($date), '50000.000');
    }

    private static function today(): DateTimeImmutable
    {
        return self::date(self::TODAY);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
