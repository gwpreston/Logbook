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
 * Economy drift (spec.md §7.24 item 7). Each segment is 600 km; a figure
 * is litres (kWh) per 100 km. "Now" is 1 Oct 2026, London.
 */
final class EconomyDriftTest extends TestCase
{
    use BuildsDriftSeries;

    protected function setUp(): void
    {
        $this->zone = new DateTimeZone('Europe/London');
    }

    public function testFlaggedAtTenPercentWithEightBaselineSegments(): void
    {
        $finding = $this->judge($this->series(8, '5.0', '5.5'));

        self::assertNotNull($finding, '5.5 is exactly 10% worse than 5.0');
        self::assertSame(5, $finding->tanks);
        self::assertEqualsWithDelta(3000.0, (float) $finding->recentDistanceKm, 0.001);
        self::assertEqualsWithDelta(165.0, (float) $finding->recentVolume, 0.001);
        self::assertEqualsWithDelta(4800.0, (float) $finding->baselineDistanceKm, 0.001);
        self::assertEqualsWithDelta(240.0, (float) $finding->baselineVolume, 0.001);
        self::assertFalse($finding->seasonChecked, 'no data for these months last year');
    }

    public function testNotAtNinePercent(): void
    {
        self::assertNull($this->judge($this->series(8, '5.0', '5.45')));
    }

    public function testNotWithSevenBaselineSegments(): void
    {
        self::assertNull($this->judge($this->series(7, '5.0', '6.0')));
    }

    public function testNotWhenTheRecentTanksAreOlderThan120Days(): void
    {
        $fills = $this->series(8, '5.0', '6.0', recentFrom: '2026-04-01', baselineFrom: '2025-06-01');

        self::assertNull($this->judge($fills), 'the last tank ended in May');
    }

    public function testThreeRecentTanksAreEnoughButTwoAreNot(): void
    {
        // Two tanks in April and May, then a gap: of the last five, only
        // those after it are recent.
        $three = $this->append(
            $this->series(8, '5.0', '6.0', recentCount: 2, recentFrom: '2026-04-15', baselineFrom: '2025-07-01'),
            ['2026-08-01', '2026-08-20', '2026-09-10'],
            '6.0',
        );
        $finding = $this->judge($three);
        self::assertNotNull($finding);
        self::assertSame(3, $finding->tanks);

        $two = $this->append(
            $this->series(8, '5.0', '6.0', recentCount: 3, recentFrom: '2026-04-15', baselineFrom: '2025-07-01'),
            ['2026-08-20', '2026-09-10'],
            '6.0',
        );
        self::assertNull($this->judge($two));
    }

    public function testAnImprovementIsNeverFlagged(): void
    {
        self::assertNull($this->judge($this->series(8, '5.0', '3.0')));
    }

    public function testWinterAgainstASummerBaselineIsFlaggedWithTheSeasonWording(): void
    {
        $now = '2027-02-20 12:00';
        $fills = $this->series(
            8,
            '5.0',
            '6.0',
            recentFrom: '2026-12-10',
            baselineFrom: '2026-03-01',
            recentEvery: 15,
            baselineEvery: 30,
        );

        $finding = $this->judge($fills, now: $now);
        self::assertNotNull($finding);
        self::assertFalse($finding->seasonChecked);
        self::assertTrue($finding->winter, 'every recent tank ended in Nov–Feb; the baseline did not');
    }

    public function testNotFlaggedWhenLastWinterWasJustAsBad(): void
    {
        $now = '2027-02-20 12:00';
        // Last winter at 6.0, then a summer at 5.0, then this winter at 6.0.
        $fills = $this->series(
            4,
            '6.0',
            '5.0',
            recentCount: 8,
            recentFrom: '2026-03-15',
            baselineFrom: '2025-12-10',
            recentEvery: 30,
            baselineEvery: 15,
        );
        $fills = $this->append($fills, ['2026-12-10', '2026-12-25', '2027-01-09', '2027-01-24', '2027-02-08'], '6.0');

        $finding = $this->judge($fills, now: $now);
        self::assertNull($finding, 'no worse than the same months a year earlier');
    }

    public function testFlaggedWhenThisWinterIsWorseThanLastWinterToo(): void
    {
        $now = '2027-02-20 12:00';
        $fills = $this->series(
            4,
            '6.0',
            '5.0',
            recentCount: 8,
            recentFrom: '2026-03-15',
            baselineFrom: '2025-12-10',
            recentEvery: 30,
            baselineEvery: 15,
        );
        $fills = $this->append($fills, ['2026-12-10', '2026-12-25', '2027-01-09', '2027-01-24', '2027-02-08'], '7.0');

        $finding = $this->judge($fills, now: $now);
        self::assertNotNull($finding);
        self::assertTrue($finding->seasonChecked, 'compared with last winter as well');
    }

    public function testAPlugInHybridsSeriesAreJudgedApart(): void
    {
        $petrol = $this->series(8, '5.0', '5.0');
        $electric = $this->series(8, '16.0', '20.0', fuel: Fuel::Electricity);
        $fills = array_merge($petrol, $electric);

        self::assertNull($this->judge($fills, EnergyKind::Liquid), 'petrol is steady');
        $finding = $this->judge($fills, EnergyKind::Electric, 15);
        self::assertNotNull($finding, 'electricity is 25% worse');
        self::assertTrue($finding->isElectric());
        self::assertNull($this->judge($fills, EnergyKind::Electric, 30), 'its own threshold');
    }

    public function testAGradeSwitchIsACauseOnlyWhenTheGradeChanged(): void
    {
        $switched = $this->series(8, '5.0', '5.6', baselineGrade: FuelGrade::E10_95, recentGrade: FuelGrade::E5_98);
        $finding = $this->judge($switched);
        self::assertSame(FuelGrade::E10_95, $finding?->gradeFrom);
        self::assertSame(FuelGrade::E5_98, $finding->gradeTo);

        $same = $this->series(8, '5.0', '5.6', baselineGrade: FuelGrade::E10_95, recentGrade: FuelGrade::E10_95);
        $finding = $this->judge($same);
        self::assertNotNull($finding);
        self::assertNull($finding->gradeFrom);
        self::assertNull($finding->gradeTo);
    }

    public function testATyreFittingIsACauseOnlyWithinTheRecentWindow(): void
    {
        $fills = $this->series(8, '5.0', '5.6');
        $inside = new DateTimeImmutable('2026-08-03', $this->zone);
        $before = new DateTimeImmutable('2026-01-10', $this->zone);
        $asked = 0;

        $finding = $this->judge($fills, fits: function () use ($inside, $before, &$asked): array {
            $asked++;

            return [$before, $inside];
        });
        self::assertSame('2026-08-03', $finding?->tyresFittedOn?->format('Y-m-d'));
        self::assertSame(1, $asked);

        self::assertNull($this->judge($fills, fits: static fn (): array => [$before])?->tyresFittedOn);

        $none = 0;
        $this->judge($this->series(8, '5.0', '5.0'), fits: function () use (&$none): array {
            $none++;

            return [];
        });
        self::assertSame(0, $none, 'asked only for a finding');
    }

    public function testShortTanksOverdueServiceAndWinterEachOnlyWhenTrue(): void
    {
        $normal = $this->judge($this->series(8, '5.0', '5.6'));
        self::assertNotNull($normal);
        self::assertFalse($normal->shortTanks);
        self::assertFalse($normal->serviceOverdue);
        self::assertFalse($normal->winter);

        $short = $this->judge($this->series(8, '5.0', '5.6', recentKm: 250));
        self::assertTrue($short?->shortTanks, '250 km is under half of 600');
        $notShort = $this->judge($this->series(8, '5.0', '5.6', recentKm: 310));
        self::assertFalse($notShort?->shortTanks);

        self::assertTrue($this->judge($this->series(8, '5.0', '5.6'), overdue: true)?->serviceOverdue);
    }

    public function testANewTankChangesTheFingerprint(): void
    {
        $fills = $this->series(8, '5.0', '5.6');
        $before = $this->judge($fills);
        $after = $this->judge($this->append($fills, ['2026-09-28'], '5.6'));
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame(Fingerprint::drift($before), Fingerprint::drift($after));
        self::assertSame(Fingerprint::drift($before), Fingerprint::drift($this->judge($fills) ?? $before));
    }

    public function testSegmentsUnder100KmDoNotCount(): void
    {
        // Eight baseline segments, but one of them is 90 km: seven count.
        $fills = $this->series(7, '5.0', '6.0', baselineFrom: '2025-11-01');
        $fills = $this->insertShort($fills);

        self::assertNull($this->judge($fills));
    }
}
