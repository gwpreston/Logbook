<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `ownership` (spec.md §7.26, Phase 14.2): the same figures as the cost of
 * ownership card, only with ViewCosts.
 */
final class OwnershipTest extends AskTestCase
{
    use ToolsATesting;

    public function testTheSameFiguresAsTheOwnershipService(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->bought($app);

        $run = $this->call($app, $owner, 'ownership', ['vehicle' => $golf->id]);
        self::assertNotNull($run->result);
        $data = new JsonDoc($run->result->data);

        $today = LocalTime::parseDate('2026-10-15');
        self::assertNotNull($today);
        $readings = $this->service($app, OdometerService::class)->history($golf)->readings;
        $expected = $this->service($app, OwnershipService::class)->forVehicle(
            $owner,
            $golf,
            $readings,
            Depreciation::of(
                $golf,
                $this->service($app, ValuationService::class)->forVehicle($golf),
                $readings,
                $today,
                $owner->preferences->timeZone(),
                'GBP',
            ),
            $today,
        );
        self::assertNotNull($expected);
        self::assertSame($expected->running->toDecimal(2), $data->get('running_costs', 'amount'));
        self::assertSame('purchase', $data->get('since', 'kind'));
        self::assertSame('2025-01-15', $data->get('since', 'date'));
        self::assertSame('/reports/ownership?vehicle=' . $golf->id . '&include_archived=1', $run->result->link);
        self::assertSame('Cost of ownership · Volkswagen Golf', $run->result->source);
    }

    public function testWithoutViewCostsNothingIsShown(): void
    {
        [$app, $owner, $access] = $this->policyApp();
        $golf = $this->bought($app);
        $access->except($golf, VehicleAbility::ViewCosts);

        $run = $this->call($app, $owner, 'ownership', ['vehicle' => $golf->id]);

        self::assertTrue((new JsonDoc($run->result?->data))->get('costs_not_shared'));
        self::assertStringNotContainsString('amount', $run->content());
        self::assertSame([], $run->result?->figures);
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        [$app] = $this->askApp();
        $golf = $this->bought($app);

        $run = $this->call($app, $this->createMember($app), 'ownership', ['vehicle' => $golf->id]);

        self::assertSame(ToolKit::NOT_FOUND, $run->error);
    }

    public function testNotOfferedWithReportsOffAndTheSchemaIsValid(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_REPORTS' => 'false']);
        self::assertNotContains('ownership', $this->offered($app, $owner));

        [$app] = $this->askApp();
        $this->assertSchemaFits($app, 'ownership', ['vehicle' => 1]);
    }

    /**
     * Bought on 15 January 2025 for £12,000, with two fill-ups and readings.
     *
     * @param App<ContainerInterface> $app
     */
    private function bought(App $app): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create(
            $this->owner($app),
            new VehicleData(
                VehicleType::Car,
                'Volkswagen',
                'Golf',
                FuelType::Petrol,
                registration: 'GO19 ABC',
                purchaseDate: LocalTime::parseDate('2025-01-15'),
                purchasePrice: '12000.00',
            ),
        );
        $this->fillUp($app, $golf, '2025-02-01T09:00:00Z', '10000', '40', '56.00');
        $this->fillUp($app, $golf, '2026-02-01T09:00:00Z', '20000', '42', '63.00');

        return $golf;
    }
}
