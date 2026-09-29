<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction as A;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Service\Tyre\TyreDistance;
use Logbook\Service\Tyre\TyreReplay;
use Logbook\Service\Tyre\TyreReplayResult;
use Logbook\Service\Tyre\TyreSequenceError;
use Logbook\Service\Tyre\TyreSequenceProblem;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * The tyre replay and distance per tyre (spec.md §7.17), without a database.
 * Tyres 1–4 are summers, 5–8 winters, 9 the spare.
 */
final class TyreReplayTest extends TestCase
{
    private const array ROAD = [P::FrontLeft, P::FrontRight, P::RearLeft, P::RearRight];

    /**
     * @param list<array{0: int, 1: A, 2?: P}> $lines tyre, action, position
     */
    private static function change(
        int $id,
        string $date,
        ?string $km,
        array $lines,
        TyreChangeKind $kind = TyreChangeKind::Fit,
    ): TyreChange {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return new TyreChange(
            $id,
            1,
            $kind,
            new TyreChangeData($day, $km),
            array_map(static fn (array $l): TyreChangeLine => new TyreChangeLine($l[0], $l[1], $l[2] ?? null), $lines),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    /**
     * @param list<int> $tyres
     * @return list<array{0: int, 1: A, 2: P}>
     */
    private static function on(array $tyres): array
    {
        return array_map(static fn (int $t, P $p): array => [$t, A::On, $p], $tyres, self::ROAD);
    }

    /**
     * @param list<int> $tyres
     * @return list<array{0: int, 1: A}>
     */
    private static function off(array $tyres): array
    {
        return array_map(static fn (int $t): array => [$t, A::Off], $tyres);
    }

    /**
     * @param list<TyreChange> $changes
     */
    private static function replay(array $changes): TyreReplayResult
    {
        $result = TyreReplay::run($changes);
        self::assertInstanceOf(TyreReplayResult::class, $result);

        return $result;
    }

    /**
     * @return array<int, string> tyre → "status position"
     */
    private static function where(TyreReplayResult $result): array
    {
        $where = [];
        foreach ($result->states as $tyre => $state) {
            $where[$tyre] = trim($state->status->value . ' ' . ($state->position->value ?? ''));
        }
        ksort($where);

        return $where;
    }

    public function testFitThenSwapThenSwapBackLeavesTheStateAsBefore(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]), TyreChangeKind::Existing);
        $toWinters = [...self::off([1, 2, 3, 4]), ...self::on([5, 6, 7, 8])];
        $toSummers = [...self::off([5, 6, 7, 8]), ...self::on([1, 2, 3, 4])];
        $winters = self::change(2, '2025-11-01', '14000', $toWinters, TyreChangeKind::Swap);
        $summers = self::change(3, '2026-03-20', '17000', $toSummers, TyreChangeKind::Swap);

        $before = self::where(self::replay([$fit]));
        $middle = self::where(self::replay([$fit, $winters]));
        $after = self::where(self::replay([$summers, $fit, $winters]));

        self::assertSame('fitted fl', $before[1]);
        self::assertSame('stored', $middle[1]);
        self::assertSame('fitted rr', $middle[8]);
        self::assertSame(array_intersect_key($after, $before), $before, 'summers back where they were');
        self::assertSame('stored', $after[5]);
        self::assertSame(P::FrontLeft, self::replay([$fit, $winters])->state(5)?->position);
        $back = self::replay([$fit, $winters, $summers]);
        self::assertSame(P::FrontLeft, $back->state(5)?->lastPosition, 'a swap puts it back there');
    }

    public function testRotateIsCheckedAsAPermutation(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]));
        $rotate = self::change(2, '2025-10-01', '15000', [
            [1, A::Move, P::RearLeft],
            [3, A::Move, P::FrontLeft],
            [2, A::Move, P::RearRight],
            [4, A::Move, P::FrontRight],
        ], TyreChangeKind::Rotate);

        $where = self::where(self::replay([$fit, $rotate]));
        self::assertSame(['fitted rl', 'fitted rr', 'fitted fl', 'fitted fr'], array_values($where));

        $clash = self::change(3, '2025-10-01', '15000', [[1, A::Move, P::RearLeft]], TyreChangeKind::Rotate);
        $error = TyreReplay::run([$fit, $clash]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::Occupied, $error->problem, 'moving onto a fitted tyre is not a permutation');
        self::assertSame(P::RearLeft, $error->position);
    }

    public function testFittingToAnOccupiedPositionIsRefused(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]));
        $again = self::change(2, '2025-09-01', '14000', [[5, A::On, P::FrontLeft]]);

        $error = TyreReplay::run([$fit, $again]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::Occupied, $error->problem);
        self::assertSame(2, $error->changeId);
        self::assertSame(5, $error->tyreId);

        $dealtWith = self::change(2, '2025-09-01', '14000', [[5, A::On, P::FrontLeft], [1, A::Retire]]);
        self::assertSame('retired', self::where(self::replay([$fit, $dealtWith]))[1]);
    }

    public function testDeletingAMiddleChangeTheLaterOnesDependOnIsRefused(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]));
        $remove = self::change(2, '2025-11-01', '14000', self::off([1, 2]), TyreChangeKind::Remove);
        $refit = self::change(3, '2026-03-01', '17000', [[1, A::On, P::FrontLeft], [2, A::On, P::FrontRight]]);
        self::replay([$fit, $remove, $refit]);

        $error = TyreReplay::run([$fit, $refit]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::AlreadyFitted, $error->problem);
        self::assertSame(3, $error->changeId);

        $twice = self::change(4, '2026-01-01', '15000', self::off([1]), TyreChangeKind::Remove);
        $error = TyreReplay::run([$fit, $remove, $twice]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::NotFitted, $error->problem, 'a stored tyre removed again');
    }

    public function testARetiredTyreCannotBeFitted(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]));
        $retire = self::change(2, '2025-11-01', '30000', [[1, A::Retire], [5, A::On, P::FrontLeft]]);
        $back = self::change(3, '2026-01-01', '31000', [[1, A::On, P::Spare]]);

        $error = TyreReplay::run([$fit, $retire, $back]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::Retired, $error->problem);
        self::assertSame(1, $error->tyreId);
    }

    public function testRepairsNeedAFittedTyreAndAnOdometerIsOptional(): void
    {
        $fit = self::change(1, '2025-04-01', '10000', self::on([1, 2, 3, 4]));
        $repair = self::change(2, '2025-05-01', null, [[2, A::Repair]], TyreChangeKind::Repair);
        $result = self::replay([$fit, $repair]);
        self::assertSame(TyreStatus::Fitted, $result->state(2)?->status);
        self::assertSame(P::FrontRight, $result->state(2)->position);

        $stored = self::change(3, '2025-06-01', null, [[9, A::Repair]], TyreChangeKind::Repair);
        $error = TyreReplay::run([$fit, $stored]);
        self::assertInstanceOf(TyreSequenceError::class, $error);
        self::assertSame(TyreSequenceProblem::NotFitted, $error->problem);
    }

    public function testChangesAreReplayedByDateThenOdometerThenId(): void
    {
        // Same day: the fit (lower odometer) comes before the removal, whatever the ids.
        $remove = self::change(1, '2025-04-01', '10050', self::off([1]), TyreChangeKind::Remove);
        $fit = self::change(2, '2025-04-01', '10000', self::on([1, 2, 3, 4]));

        self::assertSame('stored', self::where(self::replay([$remove, $fit]))[1]);
    }

    public function testARepairWithoutAnOdometerComesAfterTheDaysOtherChanges(): void
    {
        $repair = self::change(1, '2025-04-01', null, [[2, A::Repair]], TyreChangeKind::Repair);
        $fit = self::change(2, '2025-04-01', '10000', self::on([1, 2, 3, 4]));

        self::assertSame('fitted fr', self::where(self::replay([$repair, $fit]))[2], 'fitted, then repaired, the same day');
    }

    public function testSegmentsAcrossFitRotateToSpareAndBackAndSwap(): void
    {
        $changes = [
            self::change(1, '2025-01-01', '10000', [...self::on([1, 2, 3, 4]), [9, A::On, P::Spare]]),
            // 1 goes to the spare at 12,000; the spare takes its place.
            self::change(2, '2025-03-01', '12000', [[1, A::Move, P::Spare], [9, A::Move, P::FrontLeft]], TyreChangeKind::Rotate),
            // and back at 15,000.
            self::change(3, '2025-06-01', '15000', [[9, A::Move, P::Spare], [1, A::Move, P::FrontLeft]], TyreChangeKind::Rotate),
            // winters on at 18,000 (the spare is left alone).
            self::change(4, '2025-11-01', '18000', [...self::off([1, 2, 3, 4]), ...self::on([5, 6, 7, 8])], TyreChangeKind::Swap),
        ];
        $result = self::replay($changes);

        // 10,000–12,000 and 15,000–18,000; the spare time is not counted.
        self::assertSame('5000.000', TyreDistance::of($result->segments(1), '20000.000')->km);
        // 10,000–18,000 without a break.
        self::assertSame('8000.000', TyreDistance::of($result->segments(2), '20000.000')->km);
        // The spare rolled only 12,000–15,000.
        self::assertSame('3000.000', TyreDistance::of($result->segments(9), '20000.000')->km);
        // Winters are still on: open segment to the current reading.
        self::assertSame('2000.000', TyreDistance::of($result->segments(5), '20000.000')->km);
        self::assertSame('2500.500', TyreDistance::of($result->segments(5), '20500.500')->km, 'runs to the current reading');
        self::assertSame(1, $result->fittedBy[1]);
        self::assertSame(4, $result->fittedBy[5]);
    }

    public function testANegativeSegmentCountsAsZeroAndIsFlagged(): void
    {
        $result = self::replay([
            self::change(1, '2025-01-01', '10000', self::on([1, 2, 3, 4])),
            self::change(2, '2025-02-01', '9000', self::off([1]), TyreChangeKind::Remove),
            self::change(3, '2025-03-01', '9500', [[1, A::On, P::FrontLeft]]),
        ]);

        $figure = TyreDistance::of($result->segments(1), '11000.000');
        self::assertTrue($figure->flagged);
        self::assertSame('1500.000', $figure->km, 'the backwards segment adds nothing; the next one counts');
        self::assertFalse(TyreDistance::of($result->segments(2), '11000.000')->flagged);
        self::assertSame('0.000', TyreDistance::of($result->segments(2), null)->km, 'no reading yet');
    }

    public function testCostPerDistanceSplitsTheFittingAcrossItsTyres(): void
    {
        // £240 for two tyres, one of which went 24,000 km: £120 / 24,000 km.
        self::assertSame('0.005000', TyreDistance::costPerKm('240.000', 2, '24000.000'));
        self::assertNull(TyreDistance::costPerKm('240.000', 2, '0.000'), 'no distance, no figure');
        self::assertSame('0.000000', TyreDistance::costPerKm('0.000', 2, '100.000'), 'free tyres cost nothing per km');
    }
}
