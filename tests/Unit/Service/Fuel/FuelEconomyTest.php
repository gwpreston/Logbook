<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Fuel\EconomyStatus;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\ElectricEfficiencyUnit;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples for the full-to-full rules (spec.md §7.3). Distances in km,
 * volumes in litres (kWh for electricity), money in one currency.
 */
final class FuelEconomyTest extends TestCase
{
    private int $nextId = 1;

    public function testFirstFullFillIsABaselineAndLaterOnesAreMeasured(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60.00'),
            $this->fill('1500', '35', '52.50'),
            $this->fill('2100', '42', '63.00'),
        ]);

        self::assertSame(
            [EconomyStatus::Baseline, EconomyStatus::Measured, EconomyStatus::Measured],
            self::statuses($history->fills),
        );
        // 500 km on 35 L and 600 km on 42 L: 7.0 L/100 km both times.
        self::assertSame('500.000', $history->fills[1]->segment?->distanceKm);
        self::assertSame('35.000', $history->fills[1]->segment->volume);
        self::assertEqualsWithDelta(7.0, self::litresPer100Km($history->fills[2]), 1e-9);

        $summary = $history->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        self::assertSame('1100.000', $summary->measuredDistanceKm);
        self::assertSame('77.000', $summary->measuredVolume);
        self::assertSame('117.000', $summary->totalVolume, 'the baseline fill still counts as fuel bought');
        self::assertSame('175.50', $summary->totalCost);
        self::assertSame('115.50', $summary->measuredCost, 'segment costs exclude the baseline fill');
        self::assertSame('1100.000', $summary->trackedDistanceKm);
        self::assertSame(2, $summary->segments);
        // 175.50 / 117 L = 1.50 per litre; 115.50 / 1100 km = 0.105 per km.
        self::assertSame('1.500000', $summary->averagePricePerUnit());
        self::assertSame('0.105000', $summary->costPerKm());
    }

    public function testPartialFillsCountTowardsTheNextFullFill(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60.00'),
            $this->fill('1300', '20', '31.00', partial: true),
            $this->fill('1600', '22', '33.00'),
        ]);

        self::assertSame(
            [EconomyStatus::Baseline, EconomyStatus::Partial, EconomyStatus::Measured],
            self::statuses($history->fills),
        );
        self::assertNull($history->fills[1]->segment, 'no figure on the partial itself');

        $segment = $history->fills[2]->segment;
        self::assertNotNull($segment);
        self::assertSame('600.000', $segment->distanceKm);
        self::assertSame('42.000', $segment->volume, '20 L partial + 22 L full');
        self::assertSame('64.00', $segment->cost);
        self::assertSame(2, $segment->fills);
        // Full-to-full: 42 L over 600 km = 7.0. The naive per-fill figure
        // (22 L over the last 300 km = 7.33) would be wrong.
        self::assertEqualsWithDelta(7.0, self::litresPer100Km($history->fills[2]), 1e-9);
        self::assertSame('300.000', $history->fills[2]->distanceSincePreviousKm);
    }

    public function testAMissedFillUpRestartsTheMeasurementInsteadOfCorruptingIt(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60'),
            $this->fill('1500', '35', '52.5'),
            // A 35 L fill at 2000 km was never logged.
            $this->fill('2500', '35', '52.5', missedPrevious: true),
            $this->fill('3000', '35', '52.5'),
        ]);

        self::assertSame(
            [EconomyStatus::Baseline, EconomyStatus::Measured, EconomyStatus::Baseline, EconomyStatus::Measured],
            self::statuses($history->fills),
        );
        self::assertTrue($history->fills[2]->followsGap());

        // Without the flag, 1,000 km "on" 35 L would claim 3.5 L/100 km.
        $summary = $history->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        self::assertSame('1000.000', $summary->measuredDistanceKm);
        self::assertSame('70.000', $summary->measuredVolume);
    }

    public function testAMissedFillUpBeforeAPartialWaitsForTheNextFullFill(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60'),
            $this->fill('1200', '10', '15', partial: true),
            $this->fill('1500', '15', '22.5', partial: true, missedPrevious: true),
            $this->fill('1800', '30', '45'),
            $this->fill('2300', '35', '52.5'),
        ]);

        self::assertSame([
            EconomyStatus::Baseline,
            EconomyStatus::Partial,
            EconomyStatus::Unmeasured,
            EconomyStatus::Baseline,
            EconomyStatus::Measured,
        ], self::statuses($history->fills));
        self::assertSame('500.000', $history->fills[4]->segment?->distanceKm);
        self::assertSame('35.000', $history->fills[4]->segment->volume);
    }

    public function testPartialFillsBeforeTheFirstFullOneAreNotMeasured(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('500', '10', '15', partial: true),
            $this->fill('800', '30', '45'),
            $this->fill('1200', '28', '42'),
        ]);

        self::assertSame(
            [EconomyStatus::Unmeasured, EconomyStatus::Baseline, EconomyStatus::Measured],
            self::statuses($history->fills),
        );
        self::assertNull($history->fills[0]->distanceSincePreviousKm);
    }

    public function testAFullFillThatIsNotBeyondTheStartRestartsMeasuring(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('2000', '40', '60'),
            $this->fill('1990', '5', '7.5'),   // odometer typo: behind the start
            $this->fill('2490', '35', '52.5'),
        ]);

        self::assertSame(
            [EconomyStatus::Baseline, EconomyStatus::Invalid, EconomyStatus::Measured],
            self::statuses($history->fills),
        );
        self::assertSame('500.000', $history->fills[2]->segment?->distanceKm);
    }

    public function testAMissedFillUpOnTheVeryFirstEntryIsHarmless(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', missedPrevious: true),
            $this->fill('1500', '35', '52.5'),
        ]);

        self::assertSame([EconomyStatus::Baseline, EconomyStatus::Measured], self::statuses($history->fills));
    }

    public function testNoEntriesAndASingleEntry(): void
    {
        self::assertTrue(FuelEconomy::analyse([])->isEmpty());
        self::assertSame([], FuelEconomy::analyse([])->summaries);

        $single = FuelEconomy::analyse([$this->fill('1000', '40', '0')]);
        $summary = $single->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        self::assertFalse($summary->hasEconomy());
        self::assertNull($summary->costPerKm());
        self::assertSame('0.000000', $summary->averagePricePerUnit(), 'a free fill-up is valid');
        self::assertSame('0.000', $summary->trackedDistanceKm);
    }

    public function testZeroCostFillsAreValid(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '0'),
            $this->fill('1400', '28', '0'),
        ]);

        $summary = $history->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        self::assertTrue($summary->hasEconomy());
        self::assertSame('0.000000', $summary->costPerKm());
    }

    public function testLiquidFuelAndElectricityAreSeparateSeries(): void
    {
        // A plug-in hybrid: petrol fills and charges interleaved.
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60'),
            $this->fill('1100', '50', '12.50', fuel: Fuel::Electricity),
            $this->fill('1500', '20', '30', partial: true),
            $this->fill('1600', '45', '11.25', fuel: Fuel::Electricity),
            $this->fill('2000', '15', '22.5'),
        ]);

        self::assertSame([
            EconomyStatus::Baseline,
            EconomyStatus::Baseline,
            EconomyStatus::Partial,
            EconomyStatus::Measured,
            EconomyStatus::Measured,
        ], self::statuses($history->fills));
        self::assertSame('500.000', $history->fills[3]->segment?->distanceKm, 'charge to charge');
        self::assertSame('45.000', $history->fills[3]->segment->volume, 'kWh only');
        self::assertSame('1000.000', $history->fills[4]->segment?->distanceKm, 'petrol to petrol');
        self::assertSame('35.000', $history->fills[4]->segment->volume, 'litres only');
        self::assertSame('500.000', $history->fills[2]->distanceSincePreviousKm, 'since the last petrol fill');

        $liquid = $history->summary(EnergyKind::Liquid);
        $electric = $history->summary(EnergyKind::Electric);
        self::assertNotNull($liquid);
        self::assertNotNull($electric);
        self::assertSame(3, $liquid->fills);
        self::assertSame(2, $electric->fills);
        self::assertSame('95.000', $electric->totalVolume);
        self::assertSame('136.25', $history->totalCost());
        self::assertCount(1, $history->measured(EnergyKind::Electric));
    }

    public function testElectricEfficiencyFromTheSameShape(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('10000', '60', '15', fuel: Fuel::Electricity),
            $this->fill('10300', '20', '5', fuel: Fuel::Electricity, partial: true),
            $this->fill('10600', '40', '10', fuel: Fuel::Electricity),
        ]);

        $summary = $history->summary(EnergyKind::Electric);
        self::assertNotNull($summary);
        self::assertTrue($summary->hasEconomy());
        // 600 km on 60 kWh: 10 kWh/100 km, 10 km/kWh, 6.21 mi/kWh.
        $km = (float) $summary->measuredDistanceKm;
        $kwh = (float) $summary->measuredVolume;
        self::assertEqualsWithDelta(10.0, ElectricEfficiencyUnit::KwhPer100Km->fromDistanceAndEnergy($km, $kwh), 1e-9);
        self::assertEqualsWithDelta(6.2137, ElectricEfficiencyUnit::MilesPerKwh->fromDistanceAndEnergy($km, $kwh), 1e-4);
        self::assertNull($history->summary(EnergyKind::Liquid));
    }

    public function testUkAndUsMpgOfTheSameSegmentDiffer(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('0', '50', '75'),
            $this->fill('500', '35', '52.5'),
        ]);
        $segment = $history->fills[1]->segment;
        self::assertNotNull($segment);

        // 500 km on 35 L = 7 L/100 km = 40.35 mpg (UK) = 33.60 mpg (US) = 14.29 km/L.
        $uk = ConsumptionUnit::MpgUk->fromDistanceAndVolume((float) $segment->distanceKm, (float) $segment->volume);
        $us = ConsumptionUnit::MpgUs->fromDistanceAndVolume((float) $segment->distanceKm, (float) $segment->volume);
        self::assertEqualsWithDelta(40.35, $uk, 0.01);
        self::assertEqualsWithDelta(33.60, $us, 0.01);
        self::assertEqualsWithDelta(14.286, ConsumptionUnit::KmPerLitre->fromDistanceAndVolume(500.0, 35.0), 0.001);
    }

    public function testTheAverageIsWeightedNotAnAverageOfAverages(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('0', '50', '0'),
            $this->fill('100', '10', '0'),   // 10 L/100 km over a short hop
            $this->fill('1100', '50', '0'),  // 5 L/100 km over a long run
        ]);

        $summary = $history->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        // 60 L over 1100 km = 5.45 L/100 km, not (10 + 5) / 2 = 7.5.
        self::assertSame('1100.000', $summary->measuredDistanceKm);
        self::assertSame('60.000', $summary->measuredVolume);
        self::assertSame('1000.000', $summary->lastSegment?->distanceKm);
    }

    public function testNewestFirstAndLatest(): void
    {
        $history = FuelEconomy::analyse([$this->fill('1000', '40', '60'), $this->fill('1500', '35', '52.5')]);

        self::assertSame('1500.000', $history->newestFirst()[0]->entry->data->odometerKm);
        self::assertSame('1500.000', $history->latest()?->entry->data->odometerKm);
    }

    /**
     * @param list<FillEconomy> $fills
     * @return list<EconomyStatus>
     */
    private static function statuses(array $fills): array
    {
        return array_map(static fn (FillEconomy $fill): EconomyStatus => $fill->status, $fills);
    }

    private static function litresPer100Km(FillEconomy $fill): float
    {
        self::assertNotNull($fill->segment);

        return 100.0 * (float) $fill->segment->volume / (float) $fill->segment->distanceKm;
    }

    private function fill(
        string $km,
        string $volume,
        string $total,
        bool $partial = false,
        bool $missedPrevious = false,
        Fuel $fuel = Fuel::Petrol,
    ): FuelEntry {
        $id = $this->nextId++;
        $at = new DateTimeImmutable('2026-01-01 08:00', new DateTimeZone('UTC'));
        $at = $at->modify(sprintf('+%d days', $id * 7));

        return new FuelEntry(
            $id,
            1,
            new FuelEntryData(
                filledAt: $at,
                odometerKm: $km . '.000',
                fuel: $fuel,
                volume: $volume . '.000',
                pricePerUnit: '1.500000',
                totalCost: $total,
                isPartial: $partial,
                isMissedPrevious: $missedPrevious,
            ),
            $at,
            $at,
        );
    }
}
