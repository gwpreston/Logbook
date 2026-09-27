<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Doctrine\DBAL\Connection;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Support\Units\UnitPreset;
use PHPUnit\Framework\TestCase;
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
        foreach (['sessions', 'odometer_readings', 'fuel_entries', 'vehicles', 'users', 'settings'] as $table) {
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
