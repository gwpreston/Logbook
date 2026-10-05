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
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Tyre\TyreLegalFlag;
use Logbook\Service\Tyre\TyreMeasurement;
use Logbook\Service\Tyre\TyreReplay;
use Logbook\Service\Tyre\TyreReplayResult;
use Logbook\Service\Tyre\TyreThresholds;
use Logbook\Service\Tyre\TyreWear;
use Logbook\Service\Tyre\TyreWearEstimate;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * The wear estimate (spec.md §7.17): points are (the tyre's own distance,
 * depth), the rate is a least-squares slope, depth now anchors on the latest
 * measurement, and only a wearing tyre counts down.
 */
final class TyreWearTest extends TestCase
{
    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private static function point(int $change, string $km, string $mm, string $date = '2026-01-01'): TyreMeasurement
    {
        return new TyreMeasurement($change, self::day($date), $mm, $km);
    }

    /**
     * @param list<TyreMeasurement> $points
     */
    private static function estimate(
        array $points,
        string $distanceNow,
        bool $wearing = true,
        string $replaceAt = '3.000',
        string $legal = '1.600',
        ?string $currentKm = '40000.000',
        ?float $perDay = null,
    ): TyreWearEstimate {
        $today = self::day('2026-09-29');

        return TyreWear::estimate($points, $distanceNow, $wearing, $replaceAt, $legal, $currentKm, $perDay, $today);
    }

    public function testWorkedExample(): void
    {
        // 8.0 mm at 0 km and 5.0 mm at 15,000 km: 0.2 mm per 1,000 km; 2 mm to go at replace-at 3.0.
        $wear = self::estimate([self::point(1, '0.000', '8.000'), self::point(2, '15000.000', '5.000')], '15000.000');

        self::assertSame('0.200', $wear->ratePer1000Km);
        self::assertSame('5.000', $wear->depthNowMm);
        self::assertSame('10000.000', $wear->kmLeft);
        self::assertSame('50000.000', $wear->wearOutKm, 'current reading + distance left');
        self::assertNull($wear->wearOutOn, 'no date without a week of mileage history');
        self::assertFalse($wear->worn);
    }

    public function testTheWearOutDateComesFromTheDailyDistance(): void
    {
        $wear = self::estimate(
            [self::point(1, '0.000', '8.000'), self::point(2, '15000.000', '5.000')],
            '15000.000',
            perDay: 50.0,
        );

        self::assertEquals(self::day('2027-04-17'), $wear->wearOutOn, '10,000 km at 50 km a day is 200 days');
    }

    public function testOnePointIsNotKnownYet(): void
    {
        $wear = self::estimate([self::point(1, '0.000', '8.000')], '5000.000');

        self::assertFalse($wear->isKnown());
        self::assertSame('8.000', $wear->latest?->treadMm, 'the latest measurement is still shown');
    }

    public function testASpanUnderAThousandKilometresIsNotKnownYet(): void
    {
        $wear = self::estimate([self::point(1, '0.000', '8.000'), self::point(2, '999.000', '7.000')], '999.000');

        self::assertFalse($wear->isKnown());
        self::assertTrue(self::estimate(
            [self::point(1, '0.000', '8.000'), self::point(2, '1000.000', '7.900')],
            '1000.000',
        )->isKnown(), 'exactly 1,000 km is enough');
    }

    public function testAFlatOrRisingSlopeIsNotKnownYet(): void
    {
        $start = self::point(1, '0.000', '6.000');
        self::assertFalse(self::estimate([$start, self::point(2, '8000.000', '6.000')], '8000.000')->isKnown(), 'flat');
        self::assertFalse(self::estimate([$start, self::point(2, '8000.000', '6.400')], '8000.000')->isKnown(), 'rising');
    }

    public function testDepthNowAnchorsOnTheLatestMeasurement(): void
    {
        // The fitted line through these points passes well below 6.4 mm at 10,000 km.
        $points = [self::point(1, '0.000', '8.000'), self::point(2, '5000.000', '6.000'), self::point(3, '10000.000', '6.400')];
        $wear = self::estimate($points, '10000.000');

        self::assertSame('6.400', $wear->depthNowMm, 'a fresh reading is what the owner sees');

        $later = self::estimate($points, '12000.000');
        self::assertSame(
            '6.080',
            $later->depthNowMm,
            'then the rate (0.16 mm per 1,000 km) for the 2,000 km since',
        );
    }

    public function testStorageAndSpareTimeAddNoDistanceBetweenPoints(): void
    {
        $changes = [
            self::change(1, '2025-04-01', '0.000', [[1, A::On, P::FrontLeft, '8.000']]),
            // Off into storage at 5,000 km; the car then does 15,000 km on other tyres.
            self::change(2, '2025-11-01', '5000.000', [[1, A::Off, P::FrontLeft, '7.000']], TyreChangeKind::Swap),
            self::change(3, '2026-04-01', '20000.000', [[1, A::On, P::FrontLeft, '7.000']], TyreChangeKind::Swap),
            // Then the spare for a while, which is not rolling either.
            self::change(4, '2026-05-01', '22000.000', [[1, A::Move, P::Spare]], TyreChangeKind::Rotate),
            self::change(5, '2026-06-01', '30000.000', [[1, A::Move, P::FrontLeft]], TyreChangeKind::Rotate),
            self::change(6, '2026-09-01', '33000.000', [[1, A::Measure, P::FrontLeft, '6.000']], TyreChangeKind::Check),
        ];
        $result = TyreReplay::run($changes);
        self::assertInstanceOf(TyreReplayResult::class, $result);

        self::assertSame(
            ['0.000', '5000.000', '5000.000', '10000.000'],
            array_map(static fn (TyreMeasurement $m): string => $m->distanceKm, $result->measurements(1)),
        );
        // 2 mm over 10,000 km of its own: 0.2 mm per 1,000 km (on the odometer it would look like 0.06).
        self::assertSame('0.0002', TyreWear::rate($result->measurements(1)));
    }

    public function testAMeasureLineNeverMovesATyre(): void
    {
        $result = TyreReplay::run([
            self::change(1, '2026-01-01', '1000.000', [[1, A::On, P::FrontLeft], [2, A::On, P::FrontRight]]),
            self::change(2, '2026-02-01', '2000.000', [[1, A::Measure, P::FrontLeft, '7.100']], TyreChangeKind::Check),
            self::change(3, '2026-03-01', '3000.000', [[3, A::On, P::FrontLeft]]),
        ]);

        self::assertNotInstanceOf(TyreReplayResult::class, $result, 'front left is still taken after the check');
    }

    public function testAStoredTyreHasNoCountdown(): void
    {
        $wear = self::estimate(
            [self::point(1, '0.000', '8.000'), self::point(2, '15000.000', '5.000')],
            '15000.000',
            wearing: false,
        );

        self::assertFalse($wear->isKnown());
        self::assertNull($wear->kmLeft);
        self::assertSame('5.000', $wear->latest?->treadMm);
    }

    public function testThresholdsBySeasonAndVehicleType(): void
    {
        $thresholds = new TyreThresholds();

        self::assertSame('3.000', $thresholds->replaceAt(VehicleType::Car, TyreSeason::Summer));
        self::assertSame('3.000', $thresholds->replaceAt(VehicleType::Car, null));
        self::assertSame('4.000', $thresholds->replaceAt(VehicleType::Car, TyreSeason::Winter));
        self::assertSame('2.000', $thresholds->replaceAt(VehicleType::Bike, TyreSeason::Winter), 'bikes have one value');
        self::assertSame('1.000', $thresholds->legalMinimum(VehicleType::Bike));

        // A winter tyre at 3.8 mm is worn; a summer one is not.
        $points = [self::point(1, '0.000', '8.000'), self::point(2, '15000.000', '3.800')];
        self::assertTrue(self::estimate($points, '15000.000', replaceAt: '4.000')->worn);
        self::assertFalse(self::estimate($points, '15000.000', replaceAt: '3.000')->worn);
    }

    public function testAMeasuredDepthAtTheLegalMinimumIsBelowIt(): void
    {
        $wear = self::estimate([self::point(1, '0.000', '1.600')], '0.000');

        self::assertSame(TyreLegalFlag::Below, $wear->legal);
        self::assertTrue($wear->worn, 'and worn: it is under replace-at');
        self::assertNull(self::estimate([self::point(1, '0.000', '1.700')], '0.000', replaceAt: '1.000')->legal);
    }

    public function testAnEstimatedDepthAtTheLegalMinimumOnlyMayBeBelowIt(): void
    {
        // 2.0 mm measured; 0.2 mm per 1,000 km; 2,000 km later it is about 1.6 mm.
        $wear = self::estimate([self::point(1, '0.000', '4.000'), self::point(2, '10000.000', '2.000')], '12000.000');

        self::assertSame('1.600', $wear->depthNowMm);
        self::assertSame(TyreLegalFlag::MayBeBelow, $wear->legal);
        self::assertSame('0.000', $wear->kmLeft, 'distance left is clamped at 0');
    }

    public function testDeeperThanLastTime(): void
    {
        $points = [self::point(1, '0.000', '5.000'), self::point(2, '1000.000', '5.600'), self::point(3, '2000.000', '6.000')];

        self::assertSame(1, TyreWear::deeperThan($points, 2)?->changeId, '+0.6 mm is flagged');
        self::assertNull(TyreWear::deeperThan($points, 3), '+0.4 mm is not');
        self::assertNull(TyreWear::deeperThan($points, 1), 'nothing before the first');
    }

    /**
     * @param list<array{0: int, 1: A, 2?: P, 3?: string}> $lines tyre, action, position, depth
     */
    private static function change(
        int $id,
        string $date,
        ?string $km,
        array $lines,
        TyreChangeKind $kind = TyreChangeKind::Fit,
    ): TyreChange {
        return new TyreChange(
            $id,
            1,
            $kind,
            new TyreChangeData(self::day($date), $km),
            array_map(
                static fn (array $l): TyreChangeLine => new TyreChangeLine($l[0], $l[1], $l[2] ?? null, $l[3] ?? null),
                $lines,
            ),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    public function testTheTreadBarRunsFromTheFirstMeasurementToTheLegalMinimum(): void
    {
        $one = self::estimate([self::point(1, '0', '8.000')], '500');
        self::assertNull($one->barPercent(), 'one measurement: no bar, no assumed new depth');
        self::assertSame(1, $one->count);

        $half = self::estimate([self::point(1, '0', '8.000'), self::point(2, '10000', '4.800')], '10000');
        self::assertSame('8.000', $half->first?->treadMm);
        self::assertSame(50, $half->barPercent(), '(4.8 − 1.6) ÷ (8.0 − 1.6)');

        $stored = self::estimate([self::point(1, '0', '8.000'), self::point(2, '10000', '4.800')], '10000', wearing: false);
        self::assertSame(50, $stored->barPercent(), 'a stored tyre or the spare shows its bar too');

        $deeper = self::estimate([self::point(1, '0', '6.000'), self::point(2, '100', '7.000')], '100');
        self::assertSame(100, $deeper->barPercent(), 'a deeper reading is clamped full');

        $under = self::estimate([self::point(1, '0', '6.000'), self::point(2, '9000', '1.200')], '9000');
        self::assertSame(0, $under->barPercent(), 'under the legal minimum is clamped empty');

        $startedLow = self::estimate([self::point(1, '0', '1.500'), self::point(2, '900', '1.400')], '900');
        self::assertSame(0, $startedLow->barPercent(), 'first at or under the legal minimum: empty, no division');

        self::assertNull(self::estimate([], '0')->barPercent());
    }
}
