<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\GradeVerdict;
use Logbook\Domain\Fuel\GradeVerdictStatus;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Service\Fuel\GradeComparison;
use Logbook\Service\Fuel\GradeStatistics;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;
use PHPUnit\Framework\TestCase;

/**
 * The grade verdict (spec.md §7.3): price premium from fills bought close
 * together in time × the economy ratio from *Economy by grade*.
 */
final class GradeComparisonTest extends TestCase
{
    use BuildsFills;

    private const string NOW = '2025-06-01 12:00';

    public function testWorkedExampleE5AgainstE10(): void
    {
        // E10 95 at 42.1 mpg (UK), E5 98 at 40.8 mpg (UK), E5 7% dearer per litre.
        $e10 = self::volumeFor500Km(42.1);
        $e5 = self::volumeFor500Km(40.8);
        $verdict = $this->only($this->verdicts($this->scenario($e10, $e5, '1.400000', '1.498000')));

        self::assertSame(GradeVerdictStatus::Ok, $verdict->status);
        self::assertSame(FuelGrade::E5_98, $verdict->grade);
        self::assertSame(FuelGrade::E10_95, $verdict->reference);
        self::assertSame(['more', 10], [$verdict->cost()?->direction, $verdict->cost()?->percent], 'about 10% more per mile');
        self::assertSame(['more', 7], [$verdict->price()?->direction, $verdict->price()?->percent], '7% more per litre');
        self::assertSame(['more', 3], [$verdict->fuelUsed()?->direction, $verdict->fuelUsed()?->percent], '3% more fuel used');
        self::assertSame(3, $verdict->referenceSegments);
        self::assertSame(2, $verdict->gradeSegments);
        self::assertSame(3, $verdict->pairs);
    }

    public function testTheRatiosAreTheSameInEveryConsumptionUnit(): void
    {
        $e10 = self::volumeFor500Km(42.1);
        $e5 = self::volumeFor500Km(40.8);
        $verdict = $this->only($this->verdicts($this->scenario($e10, $e5, '1.400000', '1.498000')));

        // Fuel used is the inverse of distance per volume: each unit gives the same ratio.
        foreach (ConsumptionUnit::cases() as $unit) {
            $grade = $unit->fromDistanceAndVolume(1000, 2 * (float) $e5);
            $reference = $unit->fromDistanceAndVolume(1500, 3 * (float) $e10);
            self::assertNotNull($grade);
            self::assertNotNull($reference);
            $ratio = $unit === ConsumptionUnit::LitresPer100Km ? $grade / $reference : $reference / $grade;
            self::assertEqualsWithDelta((float) $verdict->economyRatio, $ratio, 1e-5, $unit->value);
        }
    }

    public function testACheaperGradeThatIsThirstierCanStillCostMore(): void
    {
        $verdict = $this->only($this->verdicts($this->scenario('30.000', '32.400', '1.400000', '1.330000')));

        self::assertSame(['less', 5], [$verdict->price()?->direction, $verdict->price()?->percent]);
        self::assertSame(['more', 8], [$verdict->fuelUsed()?->direction, $verdict->fuelUsed()?->percent]);
        self::assertSame(['more', 3], [$verdict->cost()?->direction, $verdict->cost()?->percent], '0.95 × 1.08 = 1.026');
    }

    public function testAPricierGradeThatIsMoreEconomicalCanBreakEven(): void
    {
        $verdict = $this->only($this->verdicts($this->scenario('30.000', '28.560', '1.400000', '1.470000')));

        self::assertSame(['more', 5], [$verdict->price()?->direction, $verdict->price()?->percent]);
        self::assertSame(['less', 5], [$verdict->fuelUsed()?->direction, $verdict->fuelUsed()?->percent]);
        self::assertSame(['same', 0], [$verdict->cost()?->direction, $verdict->cost()?->percent], '1.05 × 0.952 = 0.9996');
    }

    public function testAPricierGradeThatIsMoreEconomicalCanStillCostMore(): void
    {
        $verdict = $this->only($this->verdicts($this->scenario('30.000', '28.800', '1.400000', '1.540000')));

        self::assertSame(['more', 10], [$verdict->price()?->direction, $verdict->price()?->percent]);
        self::assertSame(['less', 4], [$verdict->fuelUsed()?->direction, $verdict->fuelUsed()?->percent]);
        self::assertSame(['more', 6], [$verdict->cost()?->direction, $verdict->cost()?->percent], '1.10 × 0.96 = 1.056');
    }

    public function testAFillPairsWithTheNearestReferenceFillWithin30Days(): void
    {
        $fills = [
            $this->fillAt('2025-01-01 12:00', '1000', '40', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-20 12:00', '1400', '40', '1.500000', FuelGrade::E10_95),
            $this->fillAt('2025-01-25 12:00', '1500', '40', '1.650000', FuelGrade::E5_98),
            $this->fillAt('2025-02-20 12:00', '2000', '40', '1.600000', FuelGrade::E5_98),
            $this->fillAt('2025-02-21 12:00', '2100', '40', '1.600000', FuelGrade::E5_98),
        ];

        $ratios = $this->e5Pairs($fills, 'UTC');

        // 25 Jan pairs with 20 Jan (5 days), not 1 Jan; 20 and 21 Feb are 31
        // and 32 days after 20 Jan: unpaired.
        self::assertSame(['1.100000'], $ratios);
    }

    public function testThirtyDaysPairsAndThirtyOneDoesNot(): void
    {
        $fills = [
            $this->fillAt('2025-01-01 12:00', '1000', '40', '1.500000', FuelGrade::E10_95),
            $this->fillAt('2025-01-31 12:00', '1500', '40', '1.650000', FuelGrade::E5_98),
            $this->fillAt('2025-02-01 12:00', '2000', '40', '1.800000', FuelGrade::E5_98),
        ];

        self::assertSame(['1.100000'], $this->e5Pairs($fills, 'UTC'));
    }

    public function testDaysAreCountedInTheOwnersTimeZone(): void
    {
        $fills = [
            $this->fillAt('2025-01-01 12:00', '1000', '40', '1.500000', FuelGrade::E10_95),
            // 30 days later in UTC, but already 1 February (31 days) in Berlin.
            $this->fillAt('2025-01-31 23:30', '1500', '40', '1.650000', FuelGrade::E5_98),
        ];

        self::assertSame(['1.100000'], $this->e5Pairs($fills, 'UTC'));
        self::assertSame([], $this->e5Pairs($fills, 'Europe/Berlin'));
    }

    public function testFewerThanThreePairsGivesNoVerdict(): void
    {
        // The E5 fills are all more than 30 days from any E10 fill but the first.
        $fills = [
            $this->fillAt('2025-01-01 08:00', '0', '45', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-08 08:00', '500', '30', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-15 08:00', '1000', '30', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-22 08:00', '1500', '30', '1.500000', FuelGrade::E5_98),
            $this->fillAt('2025-03-01 08:00', '2000', '30', '1.500000', FuelGrade::E5_98),
            $this->fillAt('2025-03-08 08:00', '2500', '30', '1.500000', FuelGrade::E5_98),
        ];
        $verdict = $this->only($this->verdicts($fills));

        self::assertSame(GradeVerdictStatus::NotEnoughPricePairs, $verdict->status);
        self::assertSame(1, $verdict->pairs);
        self::assertNull($verdict->cost());
        self::assertNotNull($verdict->economyRatio, 'the economy is still known');
    }

    public function testTooFewSegmentsOfAGradeGivesNoVerdict(): void
    {
        $fills = [
            $this->fillAt('2025-01-01 08:00', '0', '45', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-08 08:00', '500', '30', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-15 08:00', '1000', '30', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-22 08:00', '1500', '30', '1.500000', FuelGrade::E5_98),
            $this->fillAt('2025-01-29 08:00', '2000', '30', '1.500000', FuelGrade::E10_95),
            $this->fillAt('2025-02-05 08:00', '2500', '30', '1.500000', FuelGrade::E5_98),
        ];
        $verdict = $this->only($this->verdicts($fills));

        self::assertSame(GradeVerdictStatus::NotEnoughEconomy, $verdict->status);
        self::assertSame(1, $verdict->gradeSegments);
        self::assertNull($verdict->costRatio);
    }

    public function testOneOutlierPairDoesNotMoveTheMedian(): void
    {
        self::assertSame('1.070000', GradeComparison::median(['1.070000', '1.060000', '1.080000', '1.070000', '1.900000']));
        self::assertSame('1.070000', GradeComparison::median(['1.070000', '1.900000', '1.070000', '1.060000']));
    }

    public function testThePremiumUsesPairedFillsNotAllTimeAverages(): void
    {
        // E5 was bought only during a price spike (with a few E10 fills near
        // it); afterwards only E10, far cheaper.
        $fills = [
            $this->fillAt('2022-06-01 08:00', '0', '45', '1.850000', FuelGrade::E10_95),
            $this->fillAt('2022-06-08 08:00', '500', '30', '1.950000', FuelGrade::E5_98),
            $this->fillAt('2022-06-15 08:00', '1000', '30', '1.950000', FuelGrade::E5_98),
            $this->fillAt('2022-06-22 08:00', '1500', '30', '1.950000', FuelGrade::E5_98),
            $this->fillAt('2022-06-29 08:00', '2000', '30', '1.850000', FuelGrade::E10_95),
            $this->fillAt('2022-07-06 08:00', '2500', '30', '1.850000', FuelGrade::E10_95),
        ];
        foreach (range(1, 8) as $week) {
            $fills[] = $this->fillAt(
                (new DateTimeImmutable('2023-01-01 08:00'))->modify(sprintf('+%d weeks', $week))->format('Y-m-d H:i'),
                (string) (2500 + 500 * $week),
                '30',
                '1.450000',
                FuelGrade::E10_95,
            );
        }
        $history = FuelEconomy::analyse($fills);
        $breakdown = GradeStatistics::breakdown($history, EnergyKind::Liquid);
        $now = new DateTimeImmutable('2023-06-01');
        $verdict = $this->only(GradeComparison::verdicts($history, $breakdown, $now, new DateTimeZone('UTC')));

        self::assertSame(FuelGrade::E10_95, $verdict->reference);
        self::assertSame('1.054054', $verdict->pricePremium, '1.95 ÷ 1.85: the fills bought side by side');
        $averages = Decimal::divide(
            (string) $breakdown->row(FuelGrade::E5_98)?->averagePricePerUnit(),
            (string) $breakdown->row(FuelGrade::E10_95)?->averagePricePerUnit(),
            6,
        );
        self::assertGreaterThan(1.2, (float) $averages, 'the all-time averages would claim a 20%+ premium');
    }

    public function testAFamilyWithOneGradeHasNoComparison(): void
    {
        $fills = [
            $this->fillAt('2025-01-01 08:00', '0', '45', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-08 08:00', '500', '30', '1.400000', FuelGrade::E10_95),
            $this->fillAt('2025-01-15 08:00', '1000', '30', '1.400000'),
        ];
        $verdict = $this->only($this->verdicts($fills));

        self::assertSame(GradeVerdictStatus::SingleGrade, $verdict->status);
    }

    public function testTheReferenceIsTheMostUsedGradeByVolumeInTheLastYearTiesToTheLatest(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $old = $this->fillAt('2023-01-01 08:00', '0', '500', '1.400000', FuelGrade::E5_98);
        $a = $this->fillAt('2025-01-01 08:00', '1000', '40', '1.400000', FuelGrade::E10_95);
        $b = $this->fillAt('2025-02-01 08:00', '1500', '40', '1.500000', FuelGrade::E5_98);

        self::assertSame(FuelGrade::E5_98, GradeComparison::reference([$old, $a, $b], $now), 'equal volumes: the latest used');
        self::assertSame(FuelGrade::E10_95, GradeComparison::reference([$old, $b, $a], $now));
        self::assertSame(FuelGrade::E5_98, GradeComparison::reference([$old], $now), 'nothing this year: all time');
    }

    public function testElectricityHasNoGradeVerdict(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-01 08:00', '0', '40', '0.250000', FuelGrade::Home),
            $this->fillAt('2025-01-02 08:00', '200', '40', '0.700000', FuelGrade::DcRapid),
        ]);

        self::assertSame([], GradeComparison::verdicts(
            $history,
            GradeStatistics::breakdown($history, EnergyKind::Electric),
            new DateTimeImmutable(self::NOW),
            new DateTimeZone('UTC'),
        ));
    }

    /**
     * Litres per 500 km at a UK mpg figure, 3 places (as stored).
     */
    private static function volumeFor500Km(float $mpgUk): string
    {
        return Decimal::fromFloat(500 * (ConsumptionUnit::MpgUk->toLitresPer100Km($mpgUk) / 100), 3);
    }

    /**
     * Weekly fills: three E10 segments (the fourth fill is E5, closing the
     * last of them), then two E5 segments. The E5 fills are 7, 14 and 21
     * days after the last E10 one: three price pairs.
     *
     * @return list<FuelEntry>
     */
    private function scenario(string $referenceVolume, string $gradeVolume, string $referencePrice, string $gradePrice): array
    {
        return [
            $this->fillAt('2025-03-01 08:00', '0', '45', $referencePrice, FuelGrade::E10_95),
            $this->fillAt('2025-03-08 08:00', '500', $referenceVolume, $referencePrice, FuelGrade::E10_95),
            $this->fillAt('2025-03-15 08:00', '1000', $referenceVolume, $referencePrice, FuelGrade::E10_95),
            $this->fillAt('2025-03-22 08:00', '1500', $referenceVolume, $gradePrice, FuelGrade::E5_98),
            $this->fillAt('2025-03-29 08:00', '2000', $gradeVolume, $gradePrice, FuelGrade::E5_98),
            $this->fillAt('2025-04-05 08:00', '2500', $gradeVolume, $gradePrice, FuelGrade::E5_98),
        ];
    }

    /**
     * @param list<FuelEntry> $fills
     * @return list<GradeVerdict>
     */
    private function verdicts(array $fills): array
    {
        $history = FuelEconomy::analyse($fills);

        return GradeComparison::verdicts(
            $history,
            GradeStatistics::breakdown($history, EnergyKind::Liquid),
            new DateTimeImmutable(self::NOW),
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @param list<FuelEntry> $fills
     * @return list<string>
     */
    private function e5Pairs(array $fills, string $timeZone): array
    {
        return GradeComparison::priceRatios($fills, FuelGrade::E5_98, FuelGrade::E10_95, new DateTimeZone($timeZone));
    }

    /**
     * @param list<GradeVerdict> $verdicts
     */
    private function only(array $verdicts): GradeVerdict
    {
        self::assertCount(1, $verdicts);

        return $verdicts[0];
    }
}
