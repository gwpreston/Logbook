<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Valuation\ValuationService;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Scenario 6: a pound car and a euro car in one garage (spec.md §7.7): each
 * vehicle's figures are in its own currency on every surface, the report
 * totals per currency, and nothing is ever converted or added across them.
 */
final class TwoCurrenciesTest extends ScenarioTestCase
{
    public function testEachCurrencyHasItsOwnFiguresAndNothingIsAddedAcross(): void
    {
        [$app, $browser, $api] = $this->start();
        $golf = $this->car($app, 'Volkswagen', 'Golf', null, '10000', '500', '8000');
        $leon = $this->car($app, 'Seat', 'Leon', 'EUR', '20000', '1000', '15000');
        $golden = self::golden('two-currencies');
        $needles = self::strings($golden['report_page']);
        $never = self::strings($golden['never']);

        $this->assertOwnershipAgrees(self::section($golden['gbp']), $browser, $api, $golf);
        $this->assertOwnershipAgrees(self::section($golden['eur']), $browser, $api, $leon);

        $report = self::text(self::body($browser->get('/reports/ownership')));
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $report, 'the report');
        }
        $all = self::body($browser->get('/reports/ownership.csv'));
        $rows = self::csvRows($all);
        self::assertCount(3, $rows, 'a header and a row per vehicle: no fleet row across currencies');
        $byName = [];
        foreach (array_slice($rows, 1) as $row) {
            $byName[$row[0]] = array_combine($rows[0], $row);
        }
        self::assertSame('GBP', $byName['Volkswagen Golf']['Currency']);
        self::assertSame('2500.00', $byName['Volkswagen Golf']['Total']);
        self::assertSame('EUR', $byName['Seat Leon']['Currency']);
        self::assertSame('6000.00', $byName['Seat Leon']['Total']);
        foreach ($never as $sum) {
            self::assertStringNotContainsString($sum, $report . $all, 'a sum across currencies');
        }
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function car(
        App $app,
        string $make,
        string $model,
        ?string $currency,
        string $price,
        string $fuel,
        string $value,
    ): Vehicle {
        $car = $this->bought($app, '2025-01-01', $price, null, null, $make, $model, $currency);
        $this->reading($app, $car, '16093.44', '2025-01-01T12:00:00Z');
        $this->fillUp($app, $car, '2025-03-01T08:00:00Z', '19312.128', '50', $fuel);
        $this->reading($app, $car, '24140.16', '2026-01-01T12:00:00Z');
        $this->service($app, ValuationService::class)->create($car, new VehicleValuationData(self::date('2026-01-01'), $value));
        $this->reading($app, $car, '32186.88', '2026-09-01T09:00:00Z');

        return $car;
    }
}
