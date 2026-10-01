<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Repository\IncidentRepository;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Domain\Trip\TripData;
use Logbook\Repository\MileageRateSetRepository;
use Logbook\Repository\SavedJourneyRepository;
use Logbook\Repository\TripRepository;
use Logbook\Service\Trip\RateProvider;
use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Kernel;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Backup\BackupService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use ZipArchive;

/**
 * `bin/export-user.php` (spec.md §7.13): one user's vehicles in a backup of
 * the same format, which restores as an install of their own.
 */
final class ExportUserTest extends AppTestCase
{
    use CostFixtures;

    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    public function testExportsOnlyTheirVehiclesAndRestoresAsTheirOwnInstall(): void
    {
        if (!BackupService::isAvailable()) {
            self::markTestSkipped('Needs the zip extension.');
        }
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '55.00');
        $member = $this->createMember($app);
        $mini = $this->vehicle($app, 'Mini', 'Cooper');
        $this->connection($app)->update('vehicles', ['user_id' => $member->id], ['id' => $mini->id]);
        $this->fillUp($app, $mini, '2026-09-02T08:00:00Z', '5000', '30', '44.44');
        // Trips (Phase 22): their trip, their saved journey and rates; not the owner's.
        $at = new DateTimeImmutable('2026-09-03T08:00:00Z');
        $trip = new TripData(new DateTimeImmutable('2026-09-03'), 'Home', 'Office', purpose: 'Work');
        $this->service($app, TripRepository::class)->insert($mini->id, $trip, $at, $member->id);
        $this->service($app, TripRepository::class)->insert($golf->id, $trip, $at, $member->id);
        $journeys = $this->service($app, SavedJourneyRepository::class);
        $journeys->insert($member->id, new SavedJourneyData('Home', 'Office', '12.000'), $at);
        $journeys->insert($this->owner($app)->id, new SavedJourneyData('A', 'B'), $at);
        foreach (RateProvider::hmrc() as $set) {
            $this->service($app, MileageRateSetRepository::class)->insert($member->id, $set, $at);
        }
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, true, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        // Incidents (Phase 27.1): on their vehicle, driven by the owner, who is not in their install.
        $this->service($app, IncidentRepository::class)->insert($mini->id, new IncidentData(
            new DateTimeImmutable('2026-09-04'),
            IncidentType::ParkedDamage,
            driverUserId: $this->owner($app)->id,
        ), $at, $member->id);

        $file = tempnam(sys_get_temp_dir(), 'logbook-export-') . '.zip';
        $this->file = $file;
        [$code, $out, $err] = self::command('partner', $file);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Exported partner', $out);
        self::assertSame(1, self::command('nobody', $file)[0]);
        self::assertSame(2, self::command()[0]);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $rows = static function (string $name) use ($zip): array {
            $data = json_decode((string) $zip->getFromName('database/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertIsArray($data['rows'] ?? null);

            return $data['rows'];
        };
        self::assertCount(1, $rows('users'), 'only them');
        self::assertCount(1, $rows('vehicles'), 'only their vehicle');
        self::assertSame([], $rows('vehicle_shares'), 'no shares');
        self::assertCount(1, $rows('fuel_entries'), 'only their vehicle\'s entries');
        self::assertCount(1, $rows('trips'), 'the trips on their vehicle');
        self::assertCount(1, $rows('saved_journeys'), 'their saved journeys');
        self::assertCount(2, $rows('mileage_rate_sets'), 'their rates');
        $incidents = $rows('incidents');
        self::assertCount(1, $incidents, 'the incidents on their vehicle');
        $columns = json_decode((string) $zip->getFromName('database/incidents.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($columns);
        self::assertIsArray($columns['columns'] ?? null);
        self::assertIsArray($incidents[0]);
        $names = array_values(array_filter($columns['columns'], is_string(...)));
        $incident = array_combine($names, $incidents[0]);
        self::assertNull($incident['driver_user_id'], 'another user here is not in their install');
        self::assertSame('Pat Owner', $incident['driver_name'], 'kept by name');
        $zip->close();

        // Restored, it is an install of their own: they are its admin and own everything.
        $this->service($app, BackupService::class)->restore($file);
        $users = $this->service($app, UserRepository::class)->listAll();
        self::assertCount(1, $users);
        self::assertSame('partner', $users[0]->username);
        self::assertTrue($users[0]->isAdmin);
        $browser = $this->browserFor($app, 'partner');
        $garage = self::body($browser->get('/garage'));
        self::assertStringContainsString('Cooper', $garage);
        self::assertStringNotContainsString('Golf', $garage);
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($mini->id);
        self::assertSame($member->id, $fills[0]->createdBy);
        self::assertSame(200, $browser->get('/settings/backup')->getStatusCode(), 'an admin there');
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function command(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, Kernel::rootDir() . '/bin/export-user.php', ...array_values($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
