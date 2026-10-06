<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DateTimeImmutable;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\BackupRepository;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Demo\DemoMarkers;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoResetJob;
use Logbook\Service\Demo\DemoResetRefused;
use Logbook\Service\Demo\DemoResetter;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Storage\FileStorage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Logbook\Tests\Support\FailingSampleData;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Slim\App;

/**
 * Putting the demo back (spec.md §7.36): the data, the files and the sessions
 * go, the marker stays, a failure keeps the old data, and only a marked demo
 * is ever reset.
 */
final class DemoResetTest extends DemoTestCase
{
    public function testAResetReplacesTheDataAndTheFilesAndEndsEverySession(): void
    {
        $app = $this->demoApp();
        $clock = $this->pinClock($app, '2026-10-06T08:00:00Z');
        $browser = $this->demoBrowser($app);
        $owner = $this->demoOwner($app);
        $seeded = count($this->ownedVehicles($app, $owner->id));
        $created = $this->service($app, DemoMarkers::class)->find();
        self::assertNotNull($created);

        // A visitor's changes: a vehicle, an upload, an avatar and a stray setting.
        $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Skoda', 'Fabia', FuelType::Petrol));
        self::assertCount($seeded + 1, $this->ownedVehicles($app, $owner->id));
        $upload = $this->storedFile('attachments');
        $avatar = $this->storedFile('avatars');
        $settings = $this->service($app, SettingRepository::class);
        $settings->save('stray.global', ['changed' => true]);
        $settings->save('stray.user', true, SettingScope::User, $owner->id);
        $sessionsBefore = $this->rows($app, 'SELECT COUNT(*) FROM sessions');
        self::assertGreaterThan(0, $sessionsBefore);

        $clock->set(new DateTimeImmutable('2026-10-07T08:00:00Z'));
        $at = $this->service($app, DemoResetter::class)->reset();

        self::assertSame('2026-10-07 08:00:00', $at->format('Y-m-d H:i:s'));
        $owner = $this->demoOwner($app);
        self::assertCount($seeded, $this->ownedVehicles($app, $owner->id), 'back to the sample garage');
        self::assertSame(
            0,
            $this->rows($app, "SELECT COUNT(*) FROM vehicles WHERE model = 'Fabia'"),
        );
        self::assertSame(1, $this->rows($app, 'SELECT COUNT(*) FROM users'));
        self::assertNull($settings->find('stray.global'), 'a changed setting is back to its default');
        self::assertNull($settings->find('stray.user', SettingScope::User, $owner->id));
        self::assertSame(0, $this->rows($app, 'SELECT COUNT(*) FROM sessions'), 'every session ended');
        self::assertFileDoesNotExist($upload);
        self::assertFileDoesNotExist($avatar);

        // The sample paperwork written by the new seeding is still there.
        $paths = $this->connection($app)->fetchFirstColumn('SELECT stored_path FROM attachments');
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            self::assertFileExists($this->uploadDir() . '/' . (is_string($path) ? $path : ''));
        }

        // The marker stays, its reset time moves.
        $marker = $this->service($app, DemoMarkers::class)->find();
        self::assertNotNull($marker);
        self::assertSame($created->createdAt->getTimestamp(), $marker->createdAt->getTimestamp());
        self::assertSame($at->getTimestamp(), $marker->lastResetAt->getTimestamp());
        self::assertTrue($this->service($app, DemoMode::class)->isActive());

        // The visitor is signed out and told why.
        $response = $browser->get('/garage');
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('demo=reset', $response->getHeaderLine('Location'));
        $page = self::body($browser->follow($response));
        self::assertStringContainsString('The demo was reset. Sign in again.', $page);
        // And can sign in again with the same credentials.
        $again = $browser->post('/login', ['username' => 'demo', 'password' => self::DEMO_PASSWORD]);
        self::assertSame(303, $again->getStatusCode());
    }

    public function testAFailurePartWayLeavesTheOldDataAndItsFiles(): void
    {
        $app = $this->demoApp();
        $this->demoBrowser($app);
        $owner = $this->demoOwner($app);
        $seeded = count($this->ownedVehicles($app, $owner->id));
        $old = $this->storedFile('attachments');
        $before = $this->service($app, DemoMarkers::class)->find();

        $failing = new FailingSampleData($this->uploadDir());
        // The resetter the app would build, with the failing sample data in place of the real.
        $resetter = new DemoResetter(
            $this->connection($app),
            $this->service($app, DemoMode::class),
            $this->service($app, DemoMarkers::class),
            $failing,
            $this->service($app, FileStorage::class),
            $this->service($app, ClockInterface::class),
            $this->service($app, LoggerInterface::class),
            $this->service($app, AppSettings::class),
        );

        try {
            $resetter->reset();
            self::fail('The reset should have failed.');
        } catch (RuntimeException $e) {
            self::assertSame('The seeding failed part-way.', $e->getMessage());
        }

        self::assertCount($seeded, $this->ownedVehicles($app, $owner->id), 'the old data stays');
        self::assertSame($owner->id, $this->demoOwner($app)->id);
        self::assertFileExists($old, 'and its files');
        self::assertNotNull($failing->written);
        self::assertFileDoesNotExist($failing->written, 'what the failed seeding wrote is removed');
        self::assertEquals($before, $this->service($app, DemoMarkers::class)->find());
        $sessions = $this->rows($app, 'SELECT COUNT(*) FROM sessions');
        self::assertGreaterThan(0, $sessions, 'nobody is signed out');
    }

    public function testOnlyAMarkedDemoWithTheSwitchOnIsEverReset(): void
    {
        // Real data, DEMO_MODE on and no marker: refused.
        $real = $this->demoApp();
        $this->signedIn($real);
        $this->vehicle($real);
        try {
            $this->service($real, DemoResetter::class)->reset();
            self::fail('A real instance must never be reset.');
        } catch (DemoResetRefused) {
            self::assertCount(1, $this->ownedVehicles($real, $this->owner($real)->id), 'nothing was deleted');
        }

        // A demo with the switch off: inert, so refused too.
        $app = $this->demoApp();
        $this->seedDemo($app);
        $vehicles = count($this->ownedVehicles($app, $this->demoOwner($app)->id));
        $off = $this->createApp(['DEMO_MODE' => 'false']);
        try {
            $this->service($off, DemoResetter::class)->reset();
            self::fail('An inert demo must not be reset.');
        } catch (DemoResetRefused) {
            self::assertCount($vehicles, $this->ownedVehicles($off, $this->demoOwner($off)->id));
        }
    }

    public function testTheCommandLineRefusesWithoutTheMarker(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $this->vehicle($app);

        [$code, , $err] = $this->command(['DEMO_MODE' => 'true', 'DEMO_PASSWORD' => self::DEMO_PASSWORD]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Refused', $err);
        self::assertCount(1, $this->ownedVehicles($app, $this->owner($app)->id));

        // Without the switch it refuses as well, marker or not.
        [$code] = $this->command([]);
        self::assertSame(1, $code);
    }

    public function testTheCommandLineResetsAMarkedDemo(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);
        $owner = $this->demoOwner($app);
        $seeded = count($this->ownedVehicles($app, $owner->id));
        $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Skoda', 'Fabia', FuelType::Petrol));

        [$code, $out] = $this->command(['DEMO_MODE' => 'true', 'DEMO_PASSWORD' => self::DEMO_PASSWORD]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('The demo was reset', $out);
        self::assertCount($seeded, $this->ownedVehicles($app, $this->demoOwner($app)->id));
    }

    public function testEveryTableIsClearedOrDeliberatelyKept(): void
    {
        $app = $this->createApp();
        $schema = $this->service($app, BackupRepository::class)->tableNames();
        $cleared = DemoResetter::clearOrder($schema);

        $left = array_values(array_diff($schema, $cleared, DemoResetter::KEEP));
        self::assertSame([], $left, 'a table a reset would leave behind');
        self::assertSame([], array_values(array_diff($cleared, $schema)));
        self::assertSame($cleared, array_values(array_unique($cleared)), 'cleared once');
        self::assertSame([], array_values(array_intersect($cleared, DemoResetter::KEEP)));
        self::assertSame([], array_values(array_diff(DemoResetter::KEEP, $schema)));
        self::assertSame(['phinxlog', 'job_runs'], DemoResetter::KEEP);
        // Children before parents: the foreign keys.
        self::assertLessThan(array_search('users', $cleared, true), array_search('vehicles', $cleared, true));
        self::assertLessThan(array_search('vehicles', $cleared, true), array_search('fuel_entries', $cleared, true));
    }

    public function testTheJobIsListedOnlyWhileTheDemoIsActiveAndNotRunByAPageVisit(): void
    {
        $app = $this->demoApp(['DEMO_RESET_HOURS' => '6']);
        $clock = $this->pinClock($app, '2026-10-06T08:00:00Z');
        $this->resetDatabase($app);
        $registry = $this->service($app, JobRegistry::class);
        self::assertNull($registry->get('demo_reset'), 'not listed while there is no demo');
        self::assertNull($registry->get('demo_reset'));

        $this->seedDemo($app);
        $job = $registry->get('demo_reset');
        self::assertInstanceOf(DemoResetJob::class, $job);
        self::assertSame(6 * 3600, $job->interval());
        self::assertContains('demo_reset', array_map(static fn ($j): string => $j->name(), $registry->all()));

        $runner = $this->service($app, JobRunner::class);
        $names = array_map(static fn ($run): string => $run->job, $runner->pass(JobTrigger::PageVisit) ?? []);
        self::assertNotContains('demo_reset', $names, 'a visitor\'s request never waits for a reset');

        $names = array_map(static fn ($run): string => $run->job, $runner->pass(JobTrigger::Cron) ?? []);
        self::assertNotContains('demo_reset', $names, 'a freshly seeded demo is not reset by the first pass');

        $clock->set(new DateTimeImmutable('2026-10-06T14:01:00Z'));
        $names = array_map(static fn ($run): string => $run->job, $runner->pass(JobTrigger::Cron) ?? []);
        self::assertContains('demo_reset', $names, 'cron, Docker and the URL run it once the interval has passed');
        $run = $this->service($app, JobRunRepository::class)->latest('demo_reset');
        self::assertNotNull($run);
        self::assertSame(JobStatus::Ok, $run->status);
        self::assertStringStartsWith('Reset the demo: ', (string) $run->summary);
    }

    public function testASwitchOffInstanceHasNoResetJobAtAll(): void
    {
        $app = $this->createApp();
        self::assertNull($this->service($app, JobRegistry::class)->get('demo_reset'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function demoOwner(App $app): User
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function rows(App $app, string $sql): int
    {
        $count = $this->connection($app)->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
    }

    /** A stored file (random name, as FileStorage makes them) in the test's upload directory. */
    private function storedFile(string $directory): string
    {
        $dir = $this->uploadDir() . '/' . $directory;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . bin2hex(random_bytes(16)) . '.png';
        file_put_contents($file, 'png');

        return $file;
    }

    /**
     * Runs bin/demo-reset.php --yes against the test database.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function command(array $env): array
    {
        $process = proc_open(
            [PHP_BINARY, Kernel::rootDir() . '/bin/demo-reset.php', '--yes'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'UPLOAD_PATH' => $this->uploadDir(), 'SESSION_SECRET' => 'a-test-session-secret-of-32-chars!'] + $env,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
