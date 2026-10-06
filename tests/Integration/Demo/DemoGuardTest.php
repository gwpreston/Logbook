<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DI\Container;
use Logbook\Repository\BackupRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Demo\DemoBootstrap;
use Logbook\Service\Demo\DemoMarkers;
use Logbook\Service\Demo\DemoMarker;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRefusal;
use Logbook\Service\Demo\DemoRestriction;
use Logbook\Service\Demo\DemoState;
use Logbook\Service\Jobs\JobLocks;
use Logbook\Tests\Support\RecordingLogger;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;

/**
 * The guard (spec.md §7.36): what `DEMO_MODE` means for the database it
 * finds, row by row, and that the same switch on real data deletes nothing.
 */
final class DemoGuardTest extends DemoTestCase
{
    public function testWithTheSwitchOffAndNoMarkerItIsANormalInstance(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);

        $mode = $this->service($app, DemoMode::class);
        self::assertSame(DemoState::Off, $mode->state());
        self::assertFalse($mode->isActive());
        self::assertFalse($mode->blocks(DemoRestriction::Outbound));
    }

    public function testWithTheSwitchOffAndAMarkerTheDemoIsInertAndTheDataStays(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);
        $users = $this->service($app, UserRepository::class);
        $vehicles = count($this->ownedVehicles($app, $this->demoOwnerId($app)));
        self::assertGreaterThan(0, $vehicles);

        // The same database, started without the switch.
        $inert = $this->createApp(['DEMO_MODE' => 'false']);
        $mode = $this->service($inert, DemoMode::class);
        self::assertSame(DemoState::Inert, $mode->state());
        self::assertFalse($mode->isActive());
        self::assertFalse($mode->blocks(DemoRestriction::Administration));
        self::assertNotNull($mode->status()->marker);
        self::assertTrue($this->service($inert, DemoBootstrap::class)->ensure());
        self::assertTrue($users->exists());
        self::assertCount($vehicles, $this->ownedVehicles($inert, $this->demoOwnerId($inert)));

        // No banner, no restriction: sign in as the demo owner and use an admin page.
        $browser = $this->browserForDemoPassword($inert);
        self::assertStringNotContainsString('data-demo-banner', self::body($browser->get('/')));
        self::assertSame(200, $browser->get('/settings/users')->getStatusCode());
    }

    public function testAnEmptyDatabaseIsSeededAndMarked(): void
    {
        $app = $this->demoApp();
        $this->resetDatabase($app);
        $mode = $this->service($app, DemoMode::class);
        self::assertSame(DemoState::NeedsSeed, $mode->state());

        self::assertTrue($this->service($app, DemoBootstrap::class)->ensure());

        $mode->forget();
        self::assertSame(DemoState::Active, $mode->state());
        self::assertTrue($mode->isActive());
        $marker = $this->service($app, DemoMarkers::class)->find();
        self::assertInstanceOf(DemoMarker::class, $marker);
        self::assertSame('DEMO_MODE', $marker->seededBy);
        self::assertSame($marker->createdAt->getTimestamp(), $marker->lastResetAt->getTimestamp());

        $users = $this->service($app, UserRepository::class)->listAll();
        self::assertCount(1, $users, 'one account');
        self::assertSame('demo', $users[0]->username);
        self::assertTrue($users[0]->isAdmin);
        self::assertGreaterThanOrEqual(6, count($this->ownedVehicles($app, $users[0]->id)));
    }

    public function testTheWebStartPathSeedsAnEmptyDatabaseOnTheFirstRequest(): void
    {
        $app = $this->demoApp();
        $this->resetDatabase($app);

        $response = $this->get($app, '/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('data-demo-try', self::body($response));
        self::assertTrue($this->service($app, UserRepository::class)->exists());
        // The first-run page is gone for good.
        self::assertSame(404, $this->get($app, '/setup')->getStatusCode());
    }

    public function testASeededDemoStaysActiveAcrossStarts(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);
        $before = $this->service($app, DemoMarkers::class)->find();

        $again = $this->demoApp();
        self::assertSame(DemoState::Active, $this->service($again, DemoMode::class)->state());
        self::assertTrue($this->service($again, DemoBootstrap::class)->ensure());
        self::assertEquals($before, $this->service($again, DemoMarkers::class)->find(), 'nothing is seeded twice');
    }

    public function testTheSwitchOnRealDataIsRefusedAndNothingIsDeleted(): void
    {
        $logger = new RecordingLogger();
        $app = $this->demoApp();
        $this->useLogger($app, $logger);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $this->vehicle($app, 'Golf');
        $this->service($app, DemoMode::class)->forget();

        $mode = $this->service($app, DemoMode::class);
        self::assertSame(DemoState::Refused, $mode->state());
        self::assertSame(DemoRefusal::RealData, $mode->status()->refusal);
        self::assertFalse($mode->isActive());

        self::assertTrue($this->service($app, DemoBootstrap::class)->ensure());

        self::assertCount(1, $this->ownedVehicles($app, $owner->id), 'nothing was deleted');
        self::assertNull($this->service($app, DemoMarkers::class)->find(), 'no marker was written');
        self::assertSame(1, count($this->service($app, UserRepository::class)->listAll()));
        self::assertNotEmpty(array_filter(
            $logger->records,
            static fn (array $r): bool => $r[0] === 'error'
                && str_contains($r[1], 'holds real data')
                && str_contains($r[1], 'Remove the setting'),
        ), 'a line at error level');

        // Every admin sees why, and the app is an ordinary one: no banner, no restriction.
        $home = self::body($browser->get('/'));
        self::assertStringContainsString('DEMO_MODE is set, but this database holds real data', $home);
        self::assertStringContainsString('Remove the setting', $home);
        self::assertStringNotContainsString('data-demo-banner', $home);
        self::assertSame(200, $browser->get('/settings/users')->getStatusCode());
    }

    public function testARestoreWithConsecutiveSettingIdsStillKeepsTheMarker(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);
        $repository = $this->service($app, BackupRepository::class);
        $found = $this->connection($app)->fetchOne('SELECT id FROM settings WHERE name = ?', [DemoMarker::SETTING]);
        $markerId = is_numeric($found) ? (int) $found : 0;
        $rows = [];
        for ($i = 1; $i <= $markerId + 3; $i++) {
            $rows[] = [
                'id' => (string) $i, 'scope' => 'global', 'owner_id' => '0', 'name' => 'foreign.setting' . $i,
                'value' => '"x"', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ];
        }
        $kept = $this->service($app, DemoMarkers::class)->find();

        $repository->replaceAll(['settings' => $rows]);

        self::assertEquals($kept, $this->service($app, DemoMarkers::class)->find());
        self::assertCount(count($rows), $repository->rows('settings'));
    }

    public function testAVisitorWaitsAMomentWhileAnotherRequestIsSeeding(): void
    {
        $app = $this->demoApp();
        $this->resetDatabase($app);
        $locks = $this->service($app, JobLocks::class);
        $lock = $locks->acquire('demo_seed');
        self::assertNotNull($lock);

        try {
            $response = $this->get($app, '/login');
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('10', $response->getHeaderLine('Retry-After'));
            self::assertFalse($this->service($app, UserRepository::class)->exists(), 'nothing is seeded twice');
        } finally {
            $locks->release($lock);
        }

        self::assertSame(200, $this->get($app, '/login')->getStatusCode());
        self::assertTrue($this->service($app, UserRepository::class)->exists());
    }

    public function testTheRefusedNoticeCanBeDismissedForADay(): void
    {
        $app = $this->demoApp();
        $browser = $this->signedIn($app);
        self::assertStringContainsString('holds real data', self::body($browser->get('/')));

        $dismissed = $browser->post('/notices/demo_refused/dismiss');

        self::assertSame(303, $dismissed->getStatusCode());
        self::assertStringNotContainsString('holds real data', self::body($browser->get('/')));
    }

    public function testAPasswordThatIsMissingOrTooShortRefusesTheDemo(): void
    {
        foreach (['' => 'unset', 'short' => 'short'] as $password => $why) {
            $logger = new RecordingLogger();
            $app = $this->demoApp($password === '' ? ['DEMO_PASSWORD' => ''] : ['DEMO_PASSWORD' => $password]);
            $this->useLogger($app, $logger);
            $this->resetDatabase($app);

            $mode = $this->service($app, DemoMode::class);
            self::assertSame(DemoState::Refused, $mode->state(), $why);
            self::assertSame(DemoRefusal::Password, $mode->status()->refusal, $why);
            self::assertTrue($this->service($app, DemoBootstrap::class)->ensure());
            self::assertFalse($this->service($app, UserRepository::class)->exists(), $why . ': nothing is seeded');
            self::assertNotEmpty(array_filter(
                $logger->records,
                static fn (array $r): bool => $r[0] === 'error' && str_contains($r[1], 'DEMO_PASSWORD'),
            ), $why);
        }
    }

    public function testTheMarkerIsNeverInABackupOrAnExport(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);

        $repository = $this->service($app, BackupRepository::class);
        $names = array_column($repository->rows('settings'), 'name');
        self::assertNotContains(DemoMarker::SETTING, $names);

        // A restore neither creates the marker nor removes it.
        $rows = $repository->rows('settings');
        $rows[] = [
            'id' => '9999', 'scope' => 'global', 'owner_id' => '0', 'name' => DemoMarker::SETTING,
            'value' => '{"created_at":"2020-01-01T00:00:00+00:00","last_reset_at":"2020-01-01T00:00:00+00:00","seeded_by":"x"}',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        $kept = $this->service($app, DemoMarkers::class)->find();
        // One restored setting even carries the id the kept marker holds.
        $markerId = $this->connection($app)->fetchOne('SELECT id FROM settings WHERE name = ?', [DemoMarker::SETTING]);
        self::assertIsScalar($markerId);
        $rows[0]['id'] = (string) $markerId;
        $restored = count($rows) - 1;
        $repository->replaceAll(['settings' => $rows]);
        self::assertEquals($kept, $this->service($app, DemoMarkers::class)->find(), 'the instance\'s own marker is kept');
        self::assertCount($restored, $repository->rows('settings'), 'and every other setting is restored');

        // And a backup of a plain instance restored onto a plain one leaves it without one.
        $plain = $this->createApp();
        $this->resetDatabase($plain);
        $this->service($plain, BackupRepository::class)->replaceAll(['settings' => $rows]);
        self::assertNull($this->service($plain, DemoMarkers::class)->find());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function useLogger(App $app, LoggerInterface $logger): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(LoggerInterface::class, $logger);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function demoOwnerId(App $app): int
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertNotNull($user);

        return $user->id;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function browserForDemoPassword(App $app): TestBrowser
    {
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->post('/login', ['username' => 'demo', 'password' => self::DEMO_PASSWORD]);
        self::assertSame(303, $response->getStatusCode());

        return $browser;
    }
}
