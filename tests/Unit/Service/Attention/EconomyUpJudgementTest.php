<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Attention\Fingerprint;
use PHPUnit\Framework\TestCase;

/**
 * *Economy up* (spec.md §7.8, Phase 42): EconomyDriftTest with the sign
 * flipped. The drift test's windows, weighting, threshold and seasonal
 * test, judged for an improvement: the baseline at least the threshold
 * above the recent figure (in distance per unit, the recent figure at
 * least that much better). One judgement with two outcomes, so the drift
 * item and the insight never disagree. Segments 600 km; figures in litres
 * (kWh) per 100 km; "now" is 1 Oct 2026, London.
 */
final class EconomyUpJudgementTest extends TestCase
{
    use BuildsDriftSeries;

    protected function setUp(): void
    {
        $this->zone = new DateTimeZone('Europe/London');
    }

    public function testImprovedAtTenPercentWithEightBaselineSegments(): void
    {
        $finding = $this->judge($this->series(8, '5.5', '5.0'), both: true);

        self::assertNotNull($finding, '5.5 is exactly 10% above 5.0');
        self::assertTrue($finding->improved);
        self::assertSame(5, $finding->tanks);
        self::assertEqualsWithDelta(3000.0, (float) $finding->recentDistanceKm, 0.001);
        self::assertEqualsWithDelta(150.0, (float) $finding->recentVolume, 0.001);
        self::assertFalse($finding->seasonChecked);
        self::assertNull($this->judge($this->series(8, '5.5', '5.0')), 'of() never gives an improvement');
    }

    public function testNotAtNinePercent(): void
    {
        self::assertNull($this->judge($this->series(8, '5.45', '5.0'), both: true));
    }

    public function testNotWithSevenBaselineSegments(): void
    {
        self::assertNull($this->judge($this->series(7, '6.0', '5.0'), both: true));
    }

    public function testNotWhenTheRecentTanksAreOlderThan120Days(): void
    {
        $fills = $this->series(8, '6.0', '5.0', recentFrom: '2026-04-01', baselineFrom: '2025-06-01');

        self::assertNull($this->judge($fills, both: true));
    }

    public function testThreeRecentTanksAreEnoughButTwoAreNot(): void
    {
        $three = $this->append(
            $this->series(8, '6.0', '5.0', recentCount: 2, recentFrom: '2026-04-15', baselineFrom: '2025-07-01'),
            ['2026-08-01', '2026-08-20', '2026-09-10'],
            '5.0',
        );
        self::assertSame(3, $this->judge($three, both: true)?->tanks);

        $two = $this->append(
            $this->series(8, '6.0', '5.0', recentCount: 3, recentFrom: '2026-04-15', baselineFrom: '2025-07-01'),
            ['2026-08-20', '2026-09-10'],
            '5.0',
        );
        self::assertNull($this->judge($two, both: true));
    }

    public function testAFallIsNeverAnImprovementAndTheTwoOutcomesAgree(): void
    {
        $worse = $this->series(8, '5.0', '6.0');
        $judged = $this->judge($worse, both: true);
        self::assertNotNull($judged);
        self::assertFalse($judged->improved);
        self::assertEquals($this->judge($worse), $judged, 'the drift item reads the same judgement');

        self::assertNull($this->judge($this->series(8, '5.0', '5.2'), both: true), 'neither within the threshold');
    }

    public function testSummerAgainstAWinterBaselineIsImprovedWithTheSeasonWording(): void
    {
        $finding = $this->judge(
            $this->series(8, '6.0', '5.0', recentFrom: '2026-07-01', baselineFrom: '2025-11-01'),
            both: true,
        );
        self::assertTrue($finding?->improved);
        self::assertFalse($finding->seasonChecked, 'no data for these months last year: the season sentence');
        self::assertFalse($finding->winter, 'winter only explains a fall');
    }

    public function testNotImprovedWhenLastSummerWasJustAsGood(): void
    {
        // Last summer at 5.0, then a winter at 6.0, then this summer at 5.0.
        $fills = $this->series(
            4,
            '5.0',
            '6.0',
            recentCount: 8,
            recentFrom: '2025-10-15',
            baselineFrom: '2025-06-10',
            recentEvery: 25,
            baselineEvery: 15,
        );
        $fills = $this->append($fills, ['2026-06-10', '2026-06-25', '2026-07-10', '2026-07-25', '2026-08-09'], '5.0');

        self::assertNull($this->judge($fills, now: '2026-08-20 12:00', both: true));
    }

    public function testImprovedWhenThisSummerIsBetterThanLastSummerToo(): void
    {
        $fills = $this->series(
            4,
            '5.0',
            '6.0',
            recentCount: 8,
            recentFrom: '2025-10-15',
            baselineFrom: '2025-06-10',
            recentEvery: 25,
            baselineEvery: 15,
        );
        $fills = $this->append($fills, ['2026-06-10', '2026-06-25', '2026-07-10', '2026-07-25', '2026-08-09'], '4.0');

        $finding = $this->judge($fills, now: '2026-08-20 12:00', both: true);
        self::assertTrue($finding?->improved);
        self::assertTrue($finding->seasonChecked);
    }

    public function testAPlugInHybridsSeriesAreJudgedApartAndCanGoOppositeWays(): void
    {
        $petrol = $this->series(8, '5.0', '6.0');
        $electric = $this->series(8, '20.0', '16.0', fuel: Fuel::Electricity);
        $fills = array_merge($petrol, $electric);

        $liquid = $this->judge($fills, EnergyKind::Liquid, both: true);
        self::assertFalse($liquid?->improved, 'petrol drifted');
        $charged = $this->judge($fills, EnergyKind::Electric, 15, both: true);
        self::assertTrue($charged?->improved, 'electricity is 25% better');
        self::assertNull($this->judge($fills, EnergyKind::Electric, 30, both: true), 'its own threshold');
    }

    public function testAGradeSwitchTyresAndLongTanksAreCausesOnlyWhenTrue(): void
    {
        $switched = $this->series(8, '5.6', '5.0', baselineGrade: FuelGrade::E10_95, recentGrade: FuelGrade::E5_98);
        $finding = $this->judge($switched, both: true);
        self::assertSame(FuelGrade::E10_95, $finding?->gradeFrom);
        self::assertSame(FuelGrade::E5_98, $finding->gradeTo);

        $inside = new DateTimeImmutable('2026-08-03', $this->zone);
        $fitted = $this->judge($this->series(8, '5.6', '5.0'), fits: static fn (): array => [$inside], both: true);
        self::assertSame('2026-08-03', $fitted?->tyresFittedOn?->format('Y-m-d'));

        $long = $this->judge($this->series(8, '5.6', '5.0', recentKm: 1300), both: true);
        self::assertTrue($long?->longTanks, '1,300 km is over twice 600');
        $notLong = $this->judge($this->series(8, '5.6', '5.0', recentKm: 1100), both: true);
        self::assertFalse($notLong?->longTanks);

        $plain = $this->judge($this->series(8, '5.6', '5.0', recentKm: 250), overdue: true, both: true);
        self::assertNotNull($plain);
        self::assertFalse($plain->shortTanks, 'short tanks only explain a fall');
        self::assertFalse($plain->serviceOverdue, 'nor does an overdue service');
    }

    public function testTheFingerprintIsTheDriftItemsOwn(): void
    {
        $fills = $this->series(8, '5.6', '5.0');
        $finding = $this->judge($fills, both: true);
        self::assertNotNull($finding);
        self::assertSame(Fingerprint::drift($finding), Fingerprint::drift($this->judge($fills, both: true) ?? $finding));
    }
}
