<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Reminder\ReminderRules;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Status transitions against the lead time (spec.md §7.6).
 */
final class ReminderRulesTest extends TestCase
{
    private const string TODAY = '2026-09-27';

    /**
     * @return iterable<string, array{string, int, ReminderStatus}>
     */
    public static function dates(): iterable
    {
        yield 'well ahead' => ['2026-11-30', 30, ReminderStatus::Upcoming];
        yield 'one day before the lead time' => ['2026-10-28', 30, ReminderStatus::Upcoming];
        yield 'first day of the lead time' => ['2026-10-27', 30, ReminderStatus::Due];
        yield 'tomorrow' => ['2026-09-28', 30, ReminderStatus::Due];
        yield 'today is due, not overdue' => ['2026-09-27', 30, ReminderStatus::Due];
        yield 'yesterday' => ['2026-09-26', 30, ReminderStatus::Overdue];
        yield 'lead 0: tomorrow is still upcoming' => ['2026-09-28', 0, ReminderStatus::Upcoming];
        yield 'lead 0: due on the day' => ['2026-09-27', 0, ReminderStatus::Due];
    }

    #[DataProvider('dates')]
    public function testStatusForDate(string $dueOn, int $lead, ReminderStatus $expected): void
    {
        self::assertSame($expected, ReminderRules::statusForDate(self::date($dueOn), self::date(self::TODAY), $lead));
    }

    public function testScheduleStatusFollowsTheDueStateWithItsLeadTimes(): void
    {
        $today = self::date(self::TODAY);
        $status = static fn (NextDue $next, ?string $km, int $days = 30, string $leadKm = '1000'): ?ReminderStatus
            => ReminderRules::statusForSchedule(DueState::evaluate($next, $today, $km, null, $days, $leadKm));

        $in20Days = new NextDue(self::date('2026-10-17'));
        self::assertSame(ReminderStatus::Due, $status($in20Days, null));
        self::assertSame(ReminderStatus::Upcoming, $status($in20Days, null, 14), 'a shorter lead time');

        $distance = new NextDue(null, '50000.000');
        self::assertSame(ReminderStatus::Due, $status($distance, '49200.000'));
        self::assertSame(ReminderStatus::Upcoming, $status($distance, '49200.000', 30, '500'), 'a shorter lead distance');
        self::assertSame(ReminderStatus::Overdue, $status($distance, '50001.000'));
        self::assertNull($status(new NextDue(), null), 'nothing to judge');
    }

    public function testDismissedAndDoneStick(): void
    {
        self::assertSame(ReminderStatus::Dismissed, ReminderRules::next(ReminderStatus::Dismissed, ReminderStatus::Overdue));
        self::assertSame(ReminderStatus::Done, ReminderRules::next(ReminderStatus::Done, ReminderStatus::Due));
        self::assertSame(ReminderStatus::Overdue, ReminderRules::next(ReminderStatus::Due, ReminderStatus::Overdue));
        self::assertSame(ReminderStatus::Due, ReminderRules::next(ReminderStatus::Upcoming, ReminderStatus::Due));
    }

    public function testOnlyDueAndOverdueAreSent(): void
    {
        $notifiable = array_filter(ReminderStatus::cases(), static fn (ReminderStatus $s): bool => $s->isNotifiable());

        self::assertSame([ReminderStatus::Due, ReminderStatus::Overdue], array_values($notifiable));
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
