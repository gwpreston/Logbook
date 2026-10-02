<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Station;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Station\DuplicateReason;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * The sample data's stations (db/seeds/DemoDataSeeder.php, Phase 30.1):
 * about eight, most with positions, every non-home fill-up linked, two
 * spellings of one ready to merge, two favourites, and Home and Work.
 */
final class DemoStationsTest extends AppTestCase
{
    public function testTheDemoGarageHasStationsFavouritesAndPlaces(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);

        $stations = $this->service($app, StationRepository::class)->listActive();
        self::assertGreaterThanOrEqual(7, count($stations));
        self::assertLessThanOrEqual(9, count($stations));
        $positioned = array_filter($stations, static fn (Station $station): bool => $station->data->hasPosition());
        self::assertGreaterThan(count($stations) / 2, count($positioned), 'positions on most');

        foreach ($this->service($app, VehicleService::class)->listFleet($demo, true) as $vehicle) {
            foreach ($this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id) as $entry) {
                $data = $entry->data;
                if ($data->grade === FuelGrade::Home || $data->station === null) {
                    self::assertNull($data->stationId, 'home charging is never a station');
                } else {
                    self::assertNotNull($data->stationId, $data->station . ' is linked');
                }
            }
        }

        $pairs = $this->service($app, StationService::class)->duplicates();
        $names = [];
        foreach ($pairs as $pair) {
            if (in_array(DuplicateReason::OneEdit, $pair->reasons, true)) {
                $names[] = $pair->first->data->name . '|' . $pair->second->data->name;
            }
        }
        self::assertContains('Tesco Extra|Tesco Extra.', $names, 'ready to merge');

        self::assertCount(2, $this->service($app, StationRepository::class)->favouriteIds($demo->id));
        $places = array_map(
            static fn (Place $place): string => $place->data->name,
            $this->service($app, PlaceRepository::class)->listForUser($demo->id),
        );
        self::assertSame(['Home', 'Work'], $places);
    }
}
