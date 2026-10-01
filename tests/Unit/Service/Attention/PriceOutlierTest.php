<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Attention\Fingerprint;
use Logbook\Service\Attention\Outlier;
use Logbook\Service\Attention\PriceFinding;
use Logbook\Service\Attention\PriceOutlier;
use PHPUnit\Framework\TestCase;

/**
 * Fuel price outliers (spec.md §7.24 item 8): against the median of the
 * same fuel and grade within 30 days, 35% by default.
 */
final class PriceOutlierTest extends TestCase
{
    private int $nextId = 1;

    public function testTenTimesAndATenthAreFlaggedAsADigitSlip(): void
    {
        $usual = $this->around('1.390');
        $high = $this->fill('2026-09-12', '13.900');
        $low = $this->fill('2026-09-14', '0.139');

        $findings = self::byId(self::judge([...$usual, $high, $low]));
        self::assertCount(2, $findings);
        self::assertTrue($findings[$high->id]->digitSlip);
        self::assertSame('10.000000', $findings[$high->id]->ratio);
        self::assertSame('1.3900000', $findings[$high->id]->median);
        self::assertTrue($findings[$low->id]->digitSlip);
        self::assertSame('0.100000', $findings[$low->id]->ratio);
    }

    public function testThirtySixPercentIsFlaggedAndThirtyFourIsNot(): void
    {
        $usual = $this->around('1.000');
        $over = $this->fill('2026-09-12', '1.360');
        $under = $this->fill('2026-09-13', '0.640');
        $near = $this->fill('2026-09-14', '1.340');
        $nearLow = $this->fill('2026-09-15', '0.660');

        $findings = self::byId(self::judge([...$usual, $over, $under, $near, $nearLow]));
        self::assertSame([$over->id, $under->id], array_keys($findings));
        self::assertFalse($findings[$over->id]->digitSlip);
        self::assertFalse(Outlier::isDigitSlip('1.360000'));
    }

    public function testTheThresholdIsTheOwnersSetting(): void
    {
        $fills = [...$this->around('1.000'), $this->fill('2026-09-12', '1.360')];

        self::assertCount(1, self::judge($fills, 35));
        self::assertCount(0, self::judge($fills, 40));
    }

    public function testFillUpsMoreThan30DaysAwayDoNotCount(): void
    {
        $far = [
            $this->fill('2026-07-01', '1.000'),
            $this->fill('2026-07-05', '1.000'),
            $this->fill('2026-11-01', '1.000'),
        ];
        $fill = $this->fill('2026-09-12', '1.500');

        self::assertSame([], self::judge([...$far, $fill]), 'no neighbours: not judged');
    }

    public function testFewerThanThreeNeighboursFallsBackToTheOwnersOtherVehicles(): void
    {
        $own = [$this->fill('2026-09-01', '1.400'), $this->fill('2026-09-20', '1.400')];
        $target = $this->fill('2026-09-12', '14.000');
        $other = [
            $this->fill('2026-09-05', '1.380', vehicle: 2),
            $this->fill('2026-09-10', '1.390', vehicle: 2),
            $this->fill('2026-09-15', '1.410', vehicle: 2),
        ];
        $asked = 0;
        $owners = function () use ($own, $target, $other, &$asked): array {
            $asked++;

            return [...$own, $target, ...$other];
        };

        $findings = PriceOutlier::of([...$own, $target], $owners, 35);
        self::assertCount(1, $findings);
        self::assertSame($target->id, $findings[0]->entry->id);
        self::assertTrue($findings[0]->wider);
        self::assertSame('1.4000000', $findings[0]->median, 'the four others: 1.38, 1.39, 1.40, 1.40, 1.41 without itself');
        self::assertSame(1, $asked, 'loaded once for every fill-up that needs it');

        $enough = 0;
        $four = [...$this->around('1.400'), $this->fill('2026-09-12', '1.400')];
        PriceOutlier::of($four, function () use (&$enough): array {
            $enough++;

            return [];
        }, 35);
        self::assertSame(0, $enough, 'never asked when the vehicle has enough');
    }

    public function testHomeAndRapidChargingAreJudgedApart(): void
    {
        $fills = [
            ...$this->around('0.250', FuelGrade::Home),
            ...$this->around('0.790', FuelGrade::DcRapid),
            $this->fill('2026-09-12', '0.240', FuelGrade::Home),
            $this->fill('2026-09-13', '0.820', FuelGrade::DcRapid),
        ];

        self::assertSame([], self::judge($fills), 'each against its own kind of charging');

        $wrong = $this->fill('2026-09-14', '0.790', FuelGrade::Home);
        $findings = self::judge([...$fills, $wrong]);
        self::assertCount(1, $findings);
        self::assertSame($wrong->id, $findings[0]->entry->id);
    }

    public function testNoGradeIsItsOwnGroupAndOtherFuelsNeverMix(): void
    {
        $fills = [
            ...$this->around('1.500', null),
            ...$this->around('1.400', FuelGrade::E10_95),
            ...$this->around('1.600', null, Fuel::Diesel),
            $this->fill('2026-09-12', '1.520'),
        ];

        self::assertSame([], self::judge($fills));
    }

    public function testAFreeFillUpIsNeverFlaggedNorCounted(): void
    {
        $fills = [
            ...$this->around('0.000', FuelGrade::Home),
            ...$this->around('0.250', FuelGrade::Home),
            $free = $this->fill('2026-09-12', '0.000', FuelGrade::Home),
            $paid = $this->fill('2026-09-13', '0.260', FuelGrade::Home),
            $petrol = $this->fill('2026-09-14', '0.000'),
            ...$this->around('1.400'),
        ];

        $findings = self::judge($fills);
        self::assertSame([], $findings, 'neither the free charges nor a free tank, and the median stays 0.25');
        unset($free, $paid, $petrol);
    }

    public function testTheFingerprintChangesWhenThePriceVolumeOrTotalIsEdited(): void
    {
        $fill = $this->fill('2026-09-12', '13.900');
        $same = Fingerprint::price($fill);
        self::assertSame($same, Fingerprint::price($fill));

        foreach (
            [
                $this->edited($fill, price: '1.390'),
                $this->edited($fill, volume: '41.00'),
                $this->edited($fill, total: '57.00'),
            ] as $edit
        ) {
            self::assertNotSame($same, Fingerprint::price($edit));
        }
    }

    public function testDigitSlipBandEdges(): void
    {
        self::assertTrue(Outlier::isDigitSlip('8.000000'));
        self::assertTrue(Outlier::isDigitSlip('12.500000'));
        self::assertFalse(Outlier::isDigitSlip('7.999999'));
        self::assertFalse(Outlier::isDigitSlip('12.500001'));
        self::assertTrue(Outlier::isDigitSlip('100.000000'));
        self::assertTrue(Outlier::isDigitSlip('0.080000'));
        self::assertTrue(Outlier::isDigitSlip('0.125000'));
        self::assertFalse(Outlier::isDigitSlip('0.130000'));
        self::assertFalse(Outlier::isDigitSlip('3.000000'));
        self::assertFalse(Outlier::isDigitSlip('1.000000'));
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * @param list<FuelEntry> $fills
     * @return list<PriceFinding>
     */
    private static function judge(array $fills, int $percent = 35): array
    {
        return PriceOutlier::of($fills, static fn (): array => $fills, $percent);
    }

    /**
     * @param list<PriceFinding> $findings
     * @return array<int, PriceFinding>
     */
    private static function byId(array $findings): array
    {
        $by = [];
        foreach ($findings as $finding) {
            $by[$finding->entry->id] = $finding;
        }

        return $by;
    }

    /**
     * Three fill-ups at $price within a fortnight of 12 Sep 2026.
     *
     * @return list<FuelEntry>
     */
    private function around(string $price, ?FuelGrade $grade = null, ?Fuel $fuel = null): array
    {
        return [
            $this->fill('2026-09-02', $price, $grade, $fuel),
            $this->fill('2026-09-08', $price, $grade, $fuel),
            $this->fill('2026-09-22', $price, $grade, $fuel),
        ];
    }

    private function fill(string $date, string $price, ?FuelGrade $grade = null, ?Fuel $fuel = null, int $vehicle = 1): FuelEntry
    {
        $at = new DateTimeImmutable($date . ' 09:00', new DateTimeZone('UTC'));

        return new FuelEntry(
            $this->nextId++,
            $vehicle,
            new FuelEntryData($at, '10000', $fuel ?? $grade?->family() ?? Fuel::Petrol, '40.00', $price, '55.60', grade: $grade),
            $at,
            $at,
        );
    }

    private function edited(FuelEntry $fill, ?string $price = null, ?string $volume = null, ?string $total = null): FuelEntry
    {
        $d = $fill->data;

        return new FuelEntry(
            $fill->id,
            $fill->vehicleId,
            new FuelEntryData(
                $d->filledAt,
                $d->odometerKm,
                $d->fuel,
                $volume ?? $d->volume,
                $price ?? $d->pricePerUnit,
                $total ?? $d->totalCost,
                grade: $d->grade,
            ),
            $fill->createdAt,
            $fill->updatedAt,
        );
    }
}
