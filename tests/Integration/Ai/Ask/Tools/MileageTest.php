<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Tests\Support\AskTestCase;

/**
 * `mileage` (spec.md §7.26): distance in a period from the readings, as
 * Reports counts it.
 */
final class MileageTest extends AskTestCase
{
    use ToolsATesting;

    public function testDistanceInThePeriodAndTheTotal(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->reading($app, $golf, '20000', '2024-12-20T10:00:00Z');
        $this->reading($app, $golf, '24000', '2025-06-30T10:00:00Z');
        $this->reading($app, $golf, '29000', '2025-12-30T10:00:00Z');
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->reading($app, $polo, '1000', '2025-01-05T10:00:00Z');
        $this->reading($app, $polo, '3000', '2025-11-05T10:00:00Z');

        $one = $this->data($app, $owner, 'mileage', ['vehicle' => $golf->id, 'period' => 'last_year']);
        self::assertSame('9000.000', $one->get('vehicles', 0, 'distance', 'km'));
        $shown = $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->distance('9000.000'));
        self::assertSame($shown, $one->get('vehicles', 0, 'distance', 'display'));
        self::assertSame('29000.000', $one->get('vehicles', 0, 'latest_odometer', 'km'));
        self::assertNull($one->get('total_distance'));

        $all = $this->data($app, $owner, 'mileage', ['period' => 'last_year']);
        self::assertSame(2, $all->get('count'));
        self::assertSame('11000.000', $all->get('total_distance', 'km'));
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        [$app] = $this->askApp();
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');

        $run = $this->call($app, $this->createMember($app), 'mileage', ['vehicle' => $golf->id]);

        self::assertSame(ToolKit::NOT_FOUND, $run->error);
    }

    public function testAMemberSeesOnlyTheirOwnVehicles(): void
    {
        [$app] = $this->askApp();
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->reading($app, $golf, '20000', '2025-01-20T10:00:00Z');
        $this->reading($app, $golf, '24000', '2025-06-30T10:00:00Z');

        $data = $this->data($app, $this->createMember($app), 'mileage', ['period' => 'last_year']);

        self::assertSame(0, $data->get('count'));
    }

    public function testAlwaysOfferedAndTheSchemaIsValid(): void
    {
        [$app, $owner] = $this->askApp();

        self::assertContains('mileage', $this->offered($app, $owner));
        $this->assertSchemaFits($app, 'mileage', ['vehicle' => 1, 'from' => '2025-01-01', 'to' => '2025-12-31']);
    }
}
