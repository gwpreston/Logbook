<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\JsonDoc;

/**
 * `vehicle_summary` (spec.md §7.26): odometer, age, economy, running cost
 * and what is next, from the services the vehicle page uses.
 */
final class VehicleSummaryTest extends AskTestCase
{
    use ToolsATesting;

    public function testOdometerEconomyAndRunningCost(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2026-03-01T09:00:00Z', '10000', '50', '70.00');
        $this->fillUp($app, $bmw, '2026-06-01T09:00:00Z', '10800', '48', '67.20');

        $run = $this->call($app, $owner, 'vehicle_summary', ['vehicle' => $bmw->id]);
        self::assertNotNull($run->result);
        $data = new JsonDoc($run->result->data);

        $odometer = $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->distance('10800.000'));
        $economy = $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->economy('800', '48', false));
        self::assertSame($odometer, $data->get('odometer', 'display'));
        self::assertSame($economy, $data->get('economy', 'display'));
        self::assertSame('137.20', $data->get('running_cost_last_12_months', 'total', 'amount'));
        // £137.20 over 800 km.
        self::assertSame('0.1715', $data->get('running_cost_last_12_months', 'cost_per_km'));
        self::assertSame('/vehicles/' . $bmw->id, $run->result->link);
        self::assertSame([$bmw->id], $run->vehicleIds);
        self::assertSame('Summary · BMW 320d', $run->result->source);
    }

    public function testWithoutViewCostsThereIsNoRunningCost(): void
    {
        [$app, $owner, $access] = $this->policyApp();
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2026-03-01T09:00:00Z', '10000', '50', '70.00');
        $this->fillUp($app, $bmw, '2026-06-01T09:00:00Z', '10800', '48', '67.20');
        $access->except($bmw, VehicleAbility::ViewCosts);

        $run = $this->call($app, $owner, 'vehicle_summary', ['vehicle' => $bmw->id]);

        self::assertFalse((new JsonDoc($run->result?->data))->has('running_cost_last_12_months'));
        self::assertStringNotContainsString('137.2', $run->content());
        self::assertNotNull((new JsonDoc($run->result?->data))->get('economy'));
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        [$app] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d');

        $run = $this->call($app, $this->createMember($app), 'vehicle_summary', ['vehicle' => $bmw->id]);

        self::assertSame(ToolKit::NOT_FOUND, $run->error);
    }

    public function testAlwaysOfferedAndTheSchemaIsValid(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_FUEL' => 'false', 'FEATURES_REPORTS' => 'false']);

        self::assertContains('vehicle_summary', $this->offered($app, $owner));
        $this->assertSchemaFits($app, 'vehicle_summary', ['vehicle' => 3]);
        self::assertNotNull($this->call($app, $owner, 'vehicle_summary', [])->error, 'the vehicle is required');
    }
}
