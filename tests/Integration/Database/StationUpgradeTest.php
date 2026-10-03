<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The upgrade turns station texts into stations (spec.md §7.33
 * *Upgrading*; #131, #133): one per normalised name across the install,
 * the most common spelling as its name, the earliest owner as creator;
 * every fill-up linked, empty texts and home charging left alone; rolling
 * back restores the previous state.
 */
final class StationUpgradeTest extends AppTestCase
{
    /** The migration before the data migration (later migrations are rolled back with it). */
    private const string BEFORE_LINKS = '20261027100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function fill(App $app, int $vehicleId, string $at, ?string $station, ?FuelGrade $grade = FuelGrade::E10_95): int
    {
        $fuel = $grade?->family() ?? Fuel::Petrol;

        return $this->service($app, FuelEntryRepository::class)->insert(
            $vehicleId,
            new FuelEntryData(
                new DateTimeImmutable($at),
                '1000.000',
                $fuel,
                '40.000',
                '1.400000',
                '56.000',
                station: $station,
                grade: $grade,
            ),
            new DateTimeImmutable($at),
        );
    }

    public function testStationTextsBecomeStationsAndRollBack(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        // Before the data migration: the column exists, nothing is linked.
        Migrator::run('rollback', ['--target' => self::BEFORE_LINKS]);

        $owner = $this->createOwner($app);
        $preset = UnitPreset::Us;
        $member = $this->createMember($app, 'partner', new DisplayPreferences(
            'en_US',
            'America/New_York',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'USD',
        ));
        $vehicles = $this->service($app, VehicleRepository::class);
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $golf = $vehicles->insert($owner->id, new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol), $now);
        $kona = $vehicles->insert($member->id, new VehicleData(VehicleType::Car, 'Hyundai', 'Kona', FuelType::Electric), $now);

        $a = $this->fill($app, $kona, '2025-01-01T08:00:00Z', 'tesco antrim ');
        $b = $this->fill($app, $golf, '2025-02-01T08:00:00Z', 'Tesco Antrim');
        $c = $this->fill($app, $golf, '2025-03-01T08:00:00Z', ' Tesco  Antrim');
        $d = $this->fill($app, $golf, '2025-04-01T08:00:00Z', 'Tesco, Antrim Rd');
        $e = $this->fill($app, $golf, '2025-05-01T08:00:00Z', '   ');
        $f = $this->fill($app, $golf, '2025-06-01T08:00:00Z', null);
        $g = $this->fill($app, $kona, '2025-07-01T08:00:00Z', 'Home', FuelGrade::Home);
        $h = $this->fill($app, $kona, '2025-08-01T08:00:00Z', 'Ionity Antrim', FuelGrade::DcRapid);

        Migrator::run('migrate');

        $stations = $this->service($app, StationRepository::class)->listActive();
        $byName = [];
        foreach ($stations as $station) {
            $byName[$station->data->name] = $station;
        }
        ksort($byName);
        $expected = ['Ionity Antrim', 'Tesco Antrim', 'Tesco, Antrim Rd'];
        self::assertSame($expected, array_keys($byName), 'nothing merged across spellings');

        $tesco = $byName['Tesco Antrim'];
        self::assertSame($member->id, $tesco->createdBy, 'the owner of the earliest fill-up');
        self::assertSame('US', $tesco->data->country, 'their locale region');
        self::assertSame($owner->id, $byName['Tesco, Antrim Rd']->createdBy);
        self::assertSame('GB', $byName['Tesco, Antrim Rd']->data->country);

        $links = [];
        $entries = $this->service($app, FuelEntryRepository::class);
        foreach ([...$entries->listForVehicle($golf), ...$entries->listForVehicle($kona)] as $entry) {
            $links[$entry->id] = [$entry->data->stationId, $entry->data->station];
        }
        self::assertSame([$tesco->id, 'Tesco Antrim'], $links[$a], 'shown with the station\'s name');
        $raw = $this->connection($app)->fetchOne('SELECT station FROM fuel_entries WHERE id = ?', [$a]);
        self::assertSame('tesco antrim ', $raw, 'the text itself is unchanged');
        self::assertSame($tesco->id, $links[$b][0]);
        self::assertSame($tesco->id, $links[$c][0]);
        self::assertSame($byName['Tesco, Antrim Rd']->id, $links[$d][0]);
        self::assertNull($links[$e][0], 'a blank text');
        self::assertNull($links[$f][0], 'no text');
        self::assertSame([null, 'Home'], $links[$g], 'home charging is never a station');
        self::assertSame($byName['Ionity Antrim']->id, $links[$h][0], 'a public charger is');

        Migrator::run('rollback', ['--target' => self::BEFORE_LINKS]);
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM stations'));
        foreach ($this->service($app, FuelEntryRepository::class)->listForVehicle($golf) as $entry) {
            self::assertNull($entry->data->stationId);
        }
        $golfEntries = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf);
        self::assertSame(' Tesco  Antrim', $golfEntries[1]->data->station);
    }

    public function testTheMostCommonSpellingWinsAndTiesGoToTheFirstUsed(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        Migrator::run('rollback', ['--target' => self::BEFORE_LINKS]);
        $owner = $this->createOwner($app);
        $golf = $this->service($app, VehicleRepository::class)->insert(
            $owner->id,
            new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $this->fill($app, $golf, '2025-01-01T08:00:00Z', 'MAXOL');
        $this->fill($app, $golf, '2025-02-01T08:00:00Z', 'Maxol');
        $this->fill($app, $golf, '2025-03-01T08:00:00Z', 'Maxol');
        $this->fill($app, $golf, '2025-04-01T08:00:00Z', 'Shell');
        $this->fill($app, $golf, '2025-05-01T08:00:00Z', 'SHELL');

        Migrator::run('migrate');

        $names = array_map(
            static fn (Station $station): string => $station->data->name,
            $this->service($app, StationRepository::class)->listActive(),
        );
        sort($names);
        self::assertSame(['Maxol', 'Shell'], $names);
    }
}
