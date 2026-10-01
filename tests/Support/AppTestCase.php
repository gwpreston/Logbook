<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Doctrine\DBAL\Connection;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Support\Units\UnitPreset;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base class for tests that boot the real application (container, middleware,
 * routes) against the TEST_DB_* database and drive it with PSR-7 requests.
 *
 * Uploads go to a throwaway directory per test, and password hashing uses
 * cheap Argon2id parameters so the suite stays fast.
 */
abstract class AppTestCase extends TestCase
{
    public const string PASSWORD = 'correct horse battery staple';

    /** Minimal Argon2id cost: still Argon2id, just quick. */
    protected const array FAST_HASH = ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];

    private ?string $uploadDir = null;

    protected function tearDown(): void
    {
        if ($this->uploadDir !== null && is_dir($this->uploadDir)) {
            self::removeDirectory($this->uploadDir);
        }
        $this->uploadDir = null;
    }

    /**
     * @param array<string, string> $env environment overrides
     * @return App<ContainerInterface>
     */
    protected function createApp(array $env = []): App
    {
        $app = Kernel::createApp(Kernel::settings($env + ['UPLOAD_PATH' => $this->uploadDir()]));

        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(PasswordHasher::class, new PasswordHasher(self::FAST_HASH));

        return $app;
    }

    /**
     * Fix "now" for the app. Call before anything uses the clock (before signedIn()).
     *
     * @param App<ContainerInterface> $app
     */
    protected function pinClock(App $app, string $utc): MutableClock
    {
        $clock = new MutableClock(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ClockInterface::class, $clock);

        return $clock;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $headers
     */
    protected function get(App $app, string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /**
     * @template T of object
     * @param App<ContainerInterface> $app
     * @param class-string<T> $id
     * @return T
     */
    protected function service(App $app, string $id): object
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function connection(App $app): Connection
    {
        return $this->service($app, Connection::class);
    }

    /**
     * Empty every domain table (children first, for the foreign keys).
     *
     * @param App<ContainerInterface> $app
     */
    protected function resetDatabase(App $app): void
    {
        $connection = $this->connection($app);
        $tables = [
            'sessions',
            'invitations',
            'user_identities',
            'attention_hidden',
            'reminder_deliveries',
            'vehicle_shares',
            'vehicle_valuations',
            'trips',
            'saved_journeys',
            'mileage_rate_sets',
            'reminders',
            'expense_entries',
            'attachments',
            'odometer_readings',
            'tyre_change_lines',
            'tyre_changes',
            'tyres',
            'tyre_sets',
            'compliance_documents',
            'maintenance_entries',
            'maintenance_schedules',
            'fuel_entries',
            'vehicles',
            'api_keys',
            'users',
            'settings',
        ];
        foreach ($tables as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
    }

    /**
     * Create the owner account directly (UK units, GBP, Europe/London, en_GB).
     *
     * @param App<ContainerInterface> $app
     */
    protected function createOwner(App $app, string $username = 'owner', ?DisplayPreferences $preferences = null): User
    {
        $preset = UnitPreset::Uk;
        $preferences ??= new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );

        return $this->service($app, AuthService::class)
            ->createInitialUser(new SetupData($username, self::PASSWORD, 'Pat Owner', $preferences));
    }

    /**
     * A second, non-admin user (Phase 19), as an admin's invitation would
     * create, with the same password as the owner.
     *
     * @param App<ContainerInterface> $app
     */
    protected function createMember(
        App $app,
        string $username = 'partner',
        ?DisplayPreferences $preferences = null,
        bool $isAdmin = false,
        string $displayName = 'Sam Partner',
    ): User {
        $preset = UnitPreset::Uk;
        $preferences ??= new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );

        return $this->service($app, UserRepository::class)->insert(
            $username,
            $this->service($app, PasswordHasher::class)->hash(self::PASSWORD),
            $displayName,
            $preferences,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            $isAdmin,
        );
    }

    /**
     * A browser signed in as a freshly created owner (the database is reset first).
     *
     * @param App<ContainerInterface> $app
     */
    protected function signedIn(App $app): TestBrowser
    {
        $this->resetDatabase($app);
        $this->createOwner($app);

        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode(), 'sign-in failed');

        return $browser;
    }

    /**
     * A new browser signed in as an existing user (Phase 19: several users
     * on one install), with the test password.
     *
     * @param App<ContainerInterface> $app
     */
    protected function browserFor(App $app, string $username): TestBrowser
    {
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->post('/login', ['username' => $username, 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode(), 'sign-in failed for ' . $username);

        return $browser;
    }

    /**
     * One owner's vehicles straight from the repository, in creation order
     * (archived ones too unless $includeArchived is false).
     *
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    protected function ownedVehicles(App $app, int $userId, bool $includeArchived = true): array
    {
        $vehicles = $this->service($app, VehicleRepository::class);

        return $vehicles->listByIds($vehicles->idsOwnedBy($userId, $includeArchived ? null : VehicleStatus::Active));
    }

    protected function uploadDir(): string
    {
        return $this->uploadDir ??= sys_get_temp_dir() . '/logbook-test-uploads-' . bin2hex(random_bytes(6));
    }

    protected static function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
