<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Report\TruePart;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *A plug-in hybrid with both litres and kWh*; spec §8 *Money
 * edge cases*, bullet **Plug-in hybrids**, with §7.3 and §7.35 *What
 * changed* (#155).
 *
 * Litres and kWh are separate economy series, each measured over the
 * odometer distance between its own full fills; both count in *Fuel* for
 * cost and cost per distance. The history, in km on the odometer:
 *
 *  - petrol, full: 1 Feb at 2,000 (40 L, £60); 1 Mar at 3,000 (30 L, £45);
 *    1 Apr at 4,000 (35 L, £52.50)
 *  - charge, full: 5 Feb at 2,100 (30 kWh, £9); 10 Mar at 3,200 (20 kWh, £6)
 *
 * Bought on 1 Jan 2026 with a reading of 1,000 km; the last reading is the
 * 4,000 on 1 Apr, so 3,000 km were driven. Today is 5 Oct 2026.
 */
final class PlugInHybridTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';
    private const string TODAY = '2026-10-05';

    public function testLitresAndKilowattHoursAreSeparateSeriesEachOverItsOwnFullFills(): void
    {
        [$app] = $this->world(self::NOW);
        $car = $this->hybrid($app);

        $history = $this->service($app, FuelService::class)->history($car);
        $petrol = $history->summary(EnergyKind::Liquid);
        $charge = $history->summary(EnergyKind::Electric);
        self::assertNotNull($petrol);
        self::assertNotNull($charge);

        self::assertSame(3, $petrol->fills);
        self::assertSame(2, $petrol->segments, 'two petrol segments');
        self::assertDecimal('2000', $petrol->measuredDistanceKm, '2,000 to 3,000 and 3,000 to 4,000, charges ignored');
        self::assertDecimal('65', $petrol->measuredVolume, '30 + 35 litres');
        self::assertDecimal('97.5', $petrol->measuredCost);

        self::assertSame(2, $charge->fills);
        self::assertSame(1, $charge->segments, 'one charging segment');
        self::assertDecimal('1100', $charge->measuredDistanceKm, '2,100 to 3,200: its own full fills, not the petrol ones');
        self::assertDecimal('20', $charge->measuredVolume, 'kWh');
        self::assertDecimal('6', $charge->measuredCost);

        $liquid = $history->measured(EnergyKind::Liquid);
        self::assertSame('2026-03-01', $liquid[0]->entry->data->filledAt->format('Y-m-d'));
        self::assertCount(1, $history->measured(EnergyKind::Electric));
    }

    public function testBothSeriesCountInFuelForCostAndCostPerDistanceInReports(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->hybrid($app);

        $report = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertSame(5, $report->count);
        self::assertSame('172.500', $report->group(CostGroup::Fuel)->toDecimal(3), 'petrol 157.50 + charging 15.00');
        self::assertSame('172.500', $report->total->toDecimal(3));
        self::assertDecimal('3000', $report->distanceKm, 'the odometer distance, once');
        self::assertDecimal('0.0575', $report->costPerKm, '172.50 over 3,000 km');
    }

    public function testBothSeriesCountInFuelForOwnershipAndTrueCost(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->hybrid($app);

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame('172.500', $result->ownership->running->toDecimal(3));
        self::assertDecimal('3000', $result->ownership->distanceKm);
        self::assertDecimal('0.0575', $result->ownership->runningPerKm, 'ownership: both energies');

        $since = $result->sinceBought;
        self::assertSame('172.500', $since->amount(TruePart::Fuel)?->toDecimal(3));
        self::assertDecimal('0.0575', $since->rate(TruePart::Fuel), 'true cost: one Fuel part, per distance');
        self::assertCount(2, $since->energies, 'litres and kWh, for What changed');
        $byKind = [];
        foreach ($since->energies as $energy) {
            $byKind[$energy->kind->value] = $energy;
        }
        self::assertSame('157.500', $byKind['liquid']->cost->toDecimal(3));
        self::assertDecimal('105', $byKind['liquid']->units, 'litres');
        self::assertSame('15.000', $byKind['electric']->cost->toDecimal(3));
        self::assertDecimal('50', $byKind['electric']->units, 'kWh');
        self::assertDecimal('0.0575', $result->years[0]->rate(TruePart::Fuel), 'and the year');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function hybrid(App $app): Vehicle
    {
        $car = $this->car($app, 'Prius', '2026-01-01', '20000', fuel: FuelType::Phev);
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->fillUp($app, $car, '2026-02-01T09:00:00Z', '2000', '40.000', '60.00');
        $this->charge($app, $car, '2026-02-05T09:00:00Z', '2100', '30.000', '9.00');
        $this->fillUp($app, $car, '2026-03-01T09:00:00Z', '3000', '30.000', '45.00');
        $this->charge($app, $car, '2026-03-10T09:00:00Z', '3200', '20.000', '6.00');
        $this->fillUp($app, $car, '2026-04-01T09:00:00Z', '4000', '35.000', '52.50');

        return $car;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function charge(App $app, Vehicle $car, string $utc, string $odometerKm, string $kwh, string $total): void
    {
        $this->service($app, FuelService::class)->create($car, new FuelEntryData(
            new DateTimeImmutable($utc, new DateTimeZone('UTC')),
            $odometerKm,
            Fuel::Electricity,
            $kwh,
            Decimal::divide($total, $kwh, 6),
            $total,
        ));
    }
}
