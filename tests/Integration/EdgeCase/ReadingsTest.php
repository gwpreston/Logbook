<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Service\Fuel\EconomyStatus;
use Logbook\Service\Fuel\FuelService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Phase 45.1.3, *A vehicle with no readings, one reading, or a backwards
 * reading*; spec §8 *Money edge cases*, bullet **No distance**, with §7.3
 * *Consumption is full-to-full only* and §7.7 *Distance driven* (decided #376
 * and #405: the log reaching back to the period's start; #392: where spec and
 * code already agreed).
 *
 * Economy, Reports' cost per distance, cost of ownership and true cost each
 * go without a figure rather than showing a made-up one. Every vehicle was
 * bought on 1 Jan 2026 and is owned long enough for a rate, with a cost to
 * divide, so the readings are the only reason for the dash. Today is 5 Oct
 * 2026.
 */
final class ReadingsTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';
    private const string TODAY = '2026-10-05';

    public function testAVehicleWithNoReadingsHasNoEconomyAndNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->expense($app, $car, '2026-05-01', '30.00');

        $history = $this->service($app, FuelService::class)->history($car);
        self::assertTrue($history->isEmpty());
        self::assertNull($history->summary(EnergyKind::Liquid), 'no fill-ups, no economy');
        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    public function testAVehicleWithOneReadingHasABaselineButNoEconomyAndNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        // Its only reading is the fill-up's own, on the purchase day.
        $this->fillUp($app, $car, '2026-01-01T09:00:00Z', '5000', '40.000', '60.00');

        $history = $this->service($app, FuelService::class)->history($car);
        self::assertSame(EconomyStatus::Baseline, $history->fills[0]->status, 'the first full fill is only a baseline');
        $summary = $history->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);
        self::assertFalse($summary->hasEconomy());
        self::assertNull($summary->costPerKm(), 'no measured segment, no fuel cost per distance');
        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    public function testABackwardsReadingGivesNoSegmentAndNoPerDistanceFigureButEconomyRestartsFromIt(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->fillUp($app, $car, '2026-01-01T09:00:00Z', '5000', '40.000', '60.00');
        $this->fillUp($app, $car, '2026-06-01T09:00:00Z', '4000', '30.000', '45.00');
        $this->fillUp($app, $car, '2026-08-01T09:00:00Z', '4600', '36.000', '54.00');

        $fills = $this->service($app, FuelService::class)->history($car)->fills;
        self::assertSame(EconomyStatus::Baseline, $fills[0]->status);
        self::assertSame(EconomyStatus::Invalid, $fills[1]->status, 'a full fill below the last cannot be measured');
        self::assertNull($fills[1]->segment);
        self::assertSame(EconomyStatus::Measured, $fills[2]->status, 'measuring restarts from the backwards fill');
        self::assertNotNull($fills[2]->segment);
        self::assertDecimal('600', $fills[2]->segment->distanceKm, '4,000 to 4,600');
        self::assertDecimal('36', $fills[2]->segment->volume);

        // 5,000 on the purchase day, 4,600 at the end: the period ends below where it began.
        $this->assertNoPerDistance($app, $owner, $car, self::TODAY, 3);
    }
}
