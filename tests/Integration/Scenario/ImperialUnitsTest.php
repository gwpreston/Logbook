<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;

/**
 * Scenario 7: the same car for an owner in miles and an owner in kilometres
 * (spec.md §8 *Units*): the API's canonical figures are per km either way,
 * and the page and the CSV give the owner's unit, to the same numbers.
 */
final class ImperialUnitsTest extends ScenarioTestCase
{
    public function testAnImperialOwnerSeesPerMileFiguresOnEverySurface(): void
    {
        $this->scenario('us-imperial', new DisplayPreferences(
            'en_US',
            'America/New_York',
            UnitPreset::Us->distance(),
            UnitPreset::Us->volume(),
            UnitPreset::Us->consumption(),
            'USD',
        ));
    }

    public function testAMetricOwnerSeesPerKilometreFiguresOnEverySurface(): void
    {
        $this->scenario('metric', new DisplayPreferences(
            'en_GB',
            'Europe/London',
            UnitPreset::Metric->distance(),
            UnitPreset::Metric->volume(),
            UnitPreset::Metric->consumption(),
            'GBP',
        ));
    }

    private function scenario(string $golden, DisplayPreferences $preferences): void
    {
        [$app, $browser, $api] = $this->startWith($preferences);
        $car = $this->bought($app, '2025-01-01', '10000');
        $this->reading($app, $car, '16093.44', '2025-01-01T12:00:00Z');
        $this->fillUp($app, $car, '2025-03-01T08:00:00Z', '19312.128', '50', '500');
        $this->reading($app, $car, '24140.16', '2026-01-01T12:00:00Z');
        $this->service($app, ValuationService::class)->create($car, new VehicleValuationData(self::date('2026-01-01'), '8000'));
        $this->reading($app, $car, '32186.88', '2026-09-01T09:00:00Z');

        $this->assertOwnershipAgrees(self::golden($golden), $browser, $api, $car);
    }
}
