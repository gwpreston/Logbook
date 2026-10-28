<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Valuation\ValuationService;

/**
 * Scenario 4: the only valuation is 27 months old (spec.md §7.1 *Stale
 * value*, §8 *As of*): the figures still use it, dated, and every surface
 * says it is out of date.
 */
final class StaleValuationTest extends ScenarioTestCase
{
    public function testAValuationOlderThanTwelveMonthsIsDatedAndFlaggedEverywhere(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->bought($app, '2023-01-01', '15000');
        $this->reading($app, $golf, '16093.44', '2023-01-01T12:00:00Z');
        $this->fillUp($app, $golf, '2023-06-01T08:00:00Z', '19312.128', '50', '800');
        $this->reading($app, $golf, '32186.88', '2024-06-01T12:00:00Z');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2024-06-01'), '11000'));
        $this->reading($app, $golf, '48280.32', '2026-09-01T09:00:00Z');

        $this->assertOwnershipAgrees(self::golden('stale-valuation'), $browser, $api, $golf);
    }
}
