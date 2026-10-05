<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Service\Reminder\ReminderGenerator;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Tyre\TyreCardFlag;
use Logbook\Service\Tyre\TyreDistanceFigure;
use Logbook\Service\Tyre\TyreJudgement;
use Logbook\Service\Tyre\TyreMeasurement;
use Logbook\Service\Tyre\TyreStanding;
use Logbook\Service\Tyre\TyreThresholds;
use Logbook\Service\Tyre\TyreVerdict;
use Logbook\Service\Tyre\TyreView;
use Logbook\Service\Tyre\TyreWear;
use Logbook\Service\Tyre\TyreWearEstimate;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle's tyres judged as one (spec.md §7.6, §7.17): one verdict and one
 * reminder per vehicle, due at the soonest of wear and age, with the status
 * against the schedule lead time and distance.
 */
final class TyreJudgementTest extends TestCase
{
    private const string TODAY = '2026-09-29';
    private const int LEAD_DAYS = 30;
    private const string LEAD_KM = '1000.000';

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    /**
     * A tyre with two measurements 10,000 km apart losing 0.2 mm per 1,000
     * km, now at $depthNow mm (so $depthNow - 3 mm is 5,000 km a mm to go).
     */
    private static function wearing(
        int $id,
        P $position,
        string $latestMm,
        ?float $perDay = 50.0,
        TyreStatus $status = TyreStatus::Fitted,
        ?string $dot = null,
    ): TyreView {
        $points = [
            new TyreMeasurement(1, self::day('2025-09-01'), Decimal::add($latestMm, '2.000'), '0.000'),
            new TyreMeasurement(2, self::day('2026-09-01'), $latestMm, '10000.000'),
        ];
        $wear = TyreWear::estimate(
            $points,
            '10000.000',
            $status === TyreStatus::Fitted && $position->isRolling(),
            '3.000',
            '1.600',
            '30000.000',
            $perDay,
            self::day(self::TODAY),
        );

        return self::view($id, $position, $status, $dot, $wear);
    }

    private static function view(
        int $id,
        ?P $position,
        TyreStatus $status = TyreStatus::Fitted,
        ?string $dot = null,
        ?TyreWearEstimate $wear = null,
        ?int $setId = null,
        int $ageYears = 6,
    ): TyreView {
        $code = $dot === null ? null : DotCode::parse($dot, self::day(self::TODAY));
        assert(!is_string($code));
        $tyre = new Tyre(
            $id,
            1,
            new TyreData('Michelin', 'Primacy 4', dot: $code),
            $status,
            $status === TyreStatus::Fitted ? $position : null,
            $setId,
            null,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        return new TyreView(
            $tyre,
            new TyreDistanceFigure('10000.000'),
            wear: $wear ?? new TyreWearEstimate(),
            ageLimitOn: $status === TyreStatus::Retired
                ? null
                : (new TyreThresholds(ageYears: $ageYears))->ageLimitOn($code?->manufacturedOn),
        );
    }

    /**
     * @param list<TyreView> $views
     */
    private static function judge(array $views): TyreVerdict
    {
        return TyreJudgement::judge($views, self::day(self::TODAY), self::LEAD_DAYS, self::LEAD_KM);
    }

    public function testOneVerdictForSeveralDueTyresNamesThemAll(): void
    {
        $verdict = self::judge([
            self::wearing(1, P::FrontLeft, '2.900'),
            self::wearing(2, P::FrontRight, '2.800'),
            self::wearing(3, P::RearLeft, '6.000'),
        ]);

        self::assertSame(DueStatus::Overdue, $verdict->status);
        self::assertTrue($verdict->isWorn());
        self::assertSame([1, 2], array_map(static fn (TyreStanding $s): int => $s->view->tyre->id, $verdict->named));

        $title = 'Tyres: front left and front right worn';
        $reminder = ReminderGenerator::fromTyres(7, $verdict, 42, $title, new ReminderPreferences());
        self::assertNotNull($reminder);
        self::assertSame(ReminderSource::Tyre, $reminder->source);
        self::assertSame(7, $reminder->sourceId, 'the source id is the vehicle');
        self::assertSame('42', $reminder->occurrence, 'the latest tyre change');
        self::assertSame(ReminderStatus::Overdue, $reminder->status);
    }

    public function testTheDuePointIsTheSoonestOfWearAndAge(): void
    {
        // 4.0 mm: 5,000 km left at 50 km a day = 100 days (7 Jan 2027). Week 3 of 2021: 6 years on 18 Jan 2027.
        $wear = self::wearing(1, P::FrontLeft, '4.000');
        $old = self::view(2, P::RearLeft, dot: '0321');
        $verdict = self::judge([$wear, $old]);

        self::assertSame(TyreStanding::WEAR, $verdict->reason);
        self::assertEquals(self::day('2027-01-07'), $verdict->dueOn);
        self::assertSame('35000.000', $verdict->dueKm);

        $sooner = self::view(2, P::RearLeft, dot: '5220');
        $verdict = self::judge([$wear, $sooner]);
        self::assertSame(TyreStanding::AGE, $verdict->reason);
        self::assertEquals(self::day('2026-12-21'), $verdict->dueOn, 'week 52 of 2020 + 6 years');
        self::assertNull($verdict->dueKm, 'due km only when wear is the soonest');
    }

    public function testDistanceOnlyWithUnderAWeekOfHistory(): void
    {
        $verdict = self::judge([self::wearing(1, P::FrontLeft, '4.000', perDay: null)]);

        self::assertSame(DueStatus::Ok, $verdict->status);
        self::assertNull($verdict->dueOn);
        self::assertSame('35000.000', $verdict->dueKm);
        self::assertNull(ReminderGenerator::fromTyres(1, $verdict, 1, 't', new ReminderPreferences())?->dueOn);
    }

    public function testStatusBoundariesAtTheLeadDistance(): void
    {
        // 3.2 mm: exactly 1,000 km left (the lead distance) is due; 3.21 mm (1,050 km) is not.
        self::assertSame(DueStatus::Soon, self::judge([self::wearing(1, P::FrontLeft, '3.200', perDay: null)])->status);
        self::assertSame(DueStatus::Ok, self::judge([self::wearing(1, P::FrontLeft, '3.210', perDay: null)])->status);
    }

    public function testStatusBoundariesAtTheLeadTime(): void
    {
        // 4.0 mm: 5,000 km left. At 5,000 / 30 km a day it is 30 days away (due); at 5,000 / 29 it is 173 (upcoming).
        self::assertSame(DueStatus::Soon, self::judge([self::wearing(1, P::FrontLeft, '4.000', perDay: 5000 / 30)])->status);
        self::assertSame(DueStatus::Ok, self::judge([self::wearing(1, P::FrontLeft, '4.000', perDay: 5000 / 31)])->status);
    }

    public function testAgeLimitDates(): void
    {
        // Week 09 of 2016 starts on Monday 29 February.
        $leap = self::view(1, P::FrontLeft, dot: '0916', ageYears: 1);
        self::assertEquals(self::day('2017-02-28'), $leap->ageLimitOn, '29 Feb + 1 year is 28 Feb');

        // Six years from week 40 of 2020 (28 Sep 2020) is 28 Sep 2026: yesterday.
        $verdict = self::judge([self::view(1, P::FrontLeft, dot: '4020')]);
        self::assertSame(DueStatus::Overdue, $verdict->status);
        self::assertSame(TyreStanding::AGE, $verdict->reason);

        // Due within the lead time, upcoming before it.
        self::assertSame(DueStatus::Soon, self::judge([self::view(1, P::FrontLeft, dot: '4320')])->status);
        self::assertSame(DueStatus::Ok, self::judge([self::view(1, P::FrontLeft, dot: '4820')])->status);
    }

    public function testAnAgeLimitOfZeroTurnsAgeOff(): void
    {
        $verdict = self::judge([self::view(1, P::FrontLeft, dot: '0110', ageYears: 0)]);

        self::assertFalse($verdict->isJudgeable());
    }

    public function testStoredTyresCountForAgeRetiredOnesForNothing(): void
    {
        $stored = self::view(1, null, TyreStatus::Stored, '4020');
        self::assertSame(DueStatus::Overdue, self::judge([$stored])->status);

        $retired = self::view(1, null, TyreStatus::Retired, '4020');
        self::assertFalse(self::judge([$retired])->isJudgeable());
    }

    public function testNothingJudgeableGivesNoReminder(): void
    {
        $verdict = self::judge([self::view(1, P::FrontLeft), self::wearing(2, P::Spare, '4.000')]);

        self::assertFalse($verdict->isJudgeable(), 'no DOT dates, no estimate, and the spare is not wearing');
        self::assertNull(ReminderGenerator::fromTyres(1, $verdict, 3, '', new ReminderPreferences()));
    }

    public function testAMeasuredWornTyreIsOverdueWithoutAnEstimate(): void
    {
        $one = TyreWear::estimate(
            [new TyreMeasurement(9, self::day('2026-09-20'), '2.500', '0.000')],
            '0.000',
            true,
            '3.000',
            '1.600',
            '30000.000',
            50.0,
            self::day(self::TODAY),
        );
        $verdict = self::judge([self::view(1, P::Rear, wear: $one)]);

        self::assertSame(DueStatus::Overdue, $verdict->status);
        self::assertEquals(self::day('2026-09-20'), $verdict->dueOn, 'due since the check that found it worn');
    }

    public function testTheCardFlagsMostSevereFirstWithTheOthersBelow(): void
    {
        $worn = self::wearing(1, P::FrontLeft, '2.900', dot: '0115');
        $fine = self::wearing(2, P::FrontRight, '7.000');
        $unknown = self::view(3, P::RearLeft);
        $below = self::wearing(4, P::RearRight, '1.500');
        $verdict = self::judge([$worn, $fine, $unknown, $below]);

        $keys = static fn (TyreView $view): array => array_map(
            static fn (TyreCardFlag $flag): string => $flag->key,
            TyreCardFlag::of($verdict->standing($view->tyre->id), $view),
        );
        self::assertSame(['wear_overdue', 'age_overdue'], $keys($worn), 'worn and over the age limit: both shown');
        self::assertSame(['good'], $keys($fine));
        self::assertSame([], $keys($unknown), 'nothing judgeable: no pill, the card claims nothing');
        self::assertSame(['legal_below', 'wear_overdue'], $keys($below), 'the legal minimum outranks the rest');

        $flags = TyreCardFlag::of($verdict->standing($below->tyre->id), $below);
        self::assertSame(['overdue', 'error'], [$flags[0]->tone, $flags[0]->icon]);
        $good = TyreCardFlag::of($verdict->standing($fine->tyre->id), $fine);
        self::assertSame(['valid', 'check_circle'], [$good[0]->tone, $good[0]->icon]);
    }

    public function testTheCardFlagsSoonBelowOverdue(): void
    {
        // 3.2 mm, 0.2 mm per 1,000 km: 1,000 km to replace-at, within the lead distance; the DOT
        // (week 42 of 2020) reaches 6 years within the lead time.
        $view = self::wearing(1, P::FrontLeft, '3.200', dot: '4220');
        $verdict = self::judge([$view]);

        $flags = TyreCardFlag::of($verdict->standing($view->tyre->id), $view);
        self::assertSame(['wear_soon', 'age_soon'], array_map(static fn (TyreCardFlag $f): string => $f->key, $flags));
        self::assertSame('soon', $flags[0]->tone);
        self::assertSame('warning', $flags[0]->icon);
    }
}
