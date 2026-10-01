<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Attention\DriftFinding;
use Logbook\Service\Attention\EconomyDrift;
use Logbook\Service\Attention\Fingerprint;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Tests\Unit\Service\Fuel\BuildsFills;
use PHPUnit\Framework\TestCase;

/**
 * Economy drift (spec.md §7.24 item 7). Each segment is 600 km; a figure
 * is litres (kWh) per 100 km. "Now" is 1 Oct 2026, London.
 */
final class EconomyDriftTest extends TestCase
{
    use BuildsFills;

    private const string NOW = '2026-10-01 12:00';

    private DateTimeZone $zone;
    /** @var array<string, string> odometer by fuel */
    private array $odometer = [];

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
        $fills = $this->series(8, '5.0', '6.0', recentFrom: '2026-12-10', baselineFrom: '2026-03-01', recentEvery: 15, baselineEvery: 30);

        $finding = $this->judge($fills, now: $now);
        self::assertNotNull($finding);
        self::assertFalse($finding->seasonChecked);
        self::assertTrue($finding->winter, 'every recent tank ended in Nov–Feb; the baseline did not');
    }

    public function testNotFlaggedWhenLastWinterWasJustAsBad(): void
    {
        $now = '2027-02-20 12:00';
        // Last winter at 6.0, then a summer at 5.0, then this winter at 6.0.
        $fills = $this->series(4, '6.0', '5.0', recentCount: 8, recentFrom: '2026-03-15', baselineFrom: '2025-12-10', recentEvery: 30, baselineEvery: 15);
        $fills = $this->append($fills, ['2026-12-10', '2026-12-25', '2027-01-09', '2027-01-24', '2027-02-08'], '6.0');

        $finding = $this->judge($fills, now: $now);
        self::assertNull($finding, 'no worse than the same months a year earlier');
    }

    public function testFlaggedWhenThisWinterIsWorseThanLastWinterToo(): void
    {
        $now = '2027-02-20 12:00';
        $fills = $this->series(4, '6.0', '5.0', recentCount: 8, recentFrom: '2026-03-15', baselineFrom: '2025-12-10', recentEvery: 30, baselineEvery: 15);
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

    // --- Helpers -----------------------------------------------------------

    /**
     * @param list<FuelEntry> $fills
     * @param (\Closure(): list<DateTimeImmutable>)|null $fits
     */
    private function judge(
        array $fills,
        EnergyKind $kind = EnergyKind::Liquid,
        int $percent = 10,
        string $now = self::NOW,
        ?\Closure $fits = null,
        bool $overdue = false,
    ): ?DriftFinding {
        usort($fills, static fn (FuelEntry $a, FuelEntry $b): int => $a->data->filledAt <=> $b->data->filledAt);

        return EconomyDrift::of(
            FuelEconomy::analyse($fills),
            $kind,
            $percent,
            new DateTimeImmutable($now, $this->zone),
            $this->zone,
            $fits,
            $overdue,
        );
    }

    /**
     * An opening full fill, then $baseline segments at $before and
     * $recentCount at $after, every $baselineEvery / $recentEvery days.
     *
     * @return list<FuelEntry>
     */
    private function series(
        int $baseline,
        string $before,
        string $after,
        int $recentCount = 5,
        string $recentFrom = '2026-07-01',
        string $baselineFrom = '2025-11-01',
        int $recentEvery = 18,
        int $baselineEvery = 28,
        int $recentKm = 600,
        ?FuelGrade $baselineGrade = null,
        ?FuelGrade $recentGrade = null,
        Fuel $fuel = Fuel::Petrol,
    ): array {
        $key = $fuel->value;
        // One odometer: a plug-in hybrid's series share it.
        $this->odometer[$key] = '10000';
        $start = new DateTimeImmutable($baselineFrom . ' 09:00', $this->zone);
        $fills = [$this->fill($start->modify('-' . $baselineEvery . ' days'), 0, '40', $fuel, $baselineGrade)];
        for ($i = 0; $i < $baseline; $i++) {
            $fills[] = $this->fill($start->modify('+' . ($i * $baselineEvery) . ' days'), 600, self::litres($before, 600), $fuel, $baselineGrade);
        }
        $recent = new DateTimeImmutable($recentFrom . ' 09:00', $this->zone);
        for ($i = 0; $i < $recentCount; $i++) {
            $fills[] = $this->fill($recent->modify('+' . ($i * $recentEvery) . ' days'), $recentKm, self::litres($after, $recentKm), $fuel, $recentGrade);
        }
        if ($recentGrade !== null && $baseline > 0) {
            // A segment burns its opening fill's grade: the first recent
            // segment opens on the last baseline fill, so that one is the
            // recent grade too.
            $last = $fills[$baseline];
            $fills[$baseline] = $this->fill($last->data->filledAt, 0, $last->data->volume, $fuel, $recentGrade, $last->data->odometerKm);
        }

        return $fills;
    }

    /**
     * @param list<FuelEntry> $fills
     * @param list<string> $dates
     * @return list<FuelEntry>
     */
    private function append(array $fills, array $dates, string $figure, Fuel $fuel = Fuel::Petrol): array
    {
        foreach ($dates as $date) {
            $fills[] = $this->fill(new DateTimeImmutable($date . ' 09:00', $this->zone), 600, self::litres($figure, 600), $fuel, null);
        }

        return $fills;
    }

    /**
     * Replaces the second baseline segment with a 90 km one.
     *
     * @param list<FuelEntry> $fills
     * @return list<FuelEntry>
     */
    private function insertShort(array $fills): array
    {
        $at = $fills[1]->data->filledAt->modify('+2 days');
        $fills[] = $this->fill($at, 0, '4.5', Fuel::Petrol, null, (string) ((int) $fills[1]->data->odometerKm + 90));
        $shift = [];
        foreach ($fills as $fill) {
            if ($fill->data->filledAt > $at) {
                $shift[] = $fill;
            }
        }
        // Push the later odometers on by 90 km so the rest stay 600 km.
        return array_map(
            fn (FuelEntry $f): FuelEntry => in_array($f, $shift, true)
                ? $this->fill($f->data->filledAt, 0, $f->data->volume, $f->data->fuel, $f->data->grade, (string) ((int) $f->data->odometerKm + 90))
                : $f,
            $fills,
        );
    }

    private function fill(
        DateTimeImmutable $at,
        int $distance,
        string $volume,
        Fuel $fuel,
        ?FuelGrade $grade,
        ?string $odometer = null,
    ): FuelEntry {
        $key = $fuel->value;
        if ($odometer === null) {
            $this->odometer[$key] = (string) ((int) ($this->odometer[$key] ?? '10000') + $distance);
            $odometer = $this->odometer[$key];
        }

        return $this->fillAt(
            $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $odometer,
            $volume,
            $fuel === Fuel::Electricity ? '0.25' : '1.40',
            $grade,
            $fuel,
        );
    }

    private static function litres(string $per100, int $km): string
    {
        return number_format((float) $per100 * $km / 100, 3, '.', '');
    }
}
