<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Closure;
use DateTimeImmutable;
use DI\Container;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistorySecrets;
use Logbook\Service\MotHistory\Uk\DvsaProvider;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared set-up for the MOT history tests (spec.md §7.38): an app whose
 * DVSA answers come from tests/Fixtures/mot-history (or $answer), every
 * request recorded, and the provider enabled with sealed credentials.
 * Nothing leaves the machine.
 */
abstract class MotHistoryTestCase extends AppTestCase
{
    protected const string NOW = '2026-10-08T09:00:00Z';
    protected const string FIXTURES = __DIR__ . '/../../Fixtures/mot-history/';

    /** @var list<array{method: string, url: string}> */
    protected array $requests = [];
    /** @var (Closure(string): ?ResponseInterface)|null a response for a vehicle URL, or null for the fixture */
    protected ?Closure $answer = null;
    /** @var App<ContainerInterface> */
    protected App $app;
    protected MutableClock $clock;
    protected User $owner;

    protected function tearDown(): void
    {
        self::clearLimits();
        parent::tearDown();
    }

    /**
     * Forget MotHistoryLimit's counts (files under var/cache/rate-limit), so
     * one test's fetches never count against the next's.
     */
    protected static function clearLimits(): void
    {
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * @param bool $enable switch the DVSA provider on
     */
    protected function start(bool $enable = true): void
    {
        self::clearLimits();
        $this->app = $this->createApp(['SESSION_SECRET' => 'a-session-secret-for-sealing-credentials-in-tests']);
        $this->resetDatabase($this->app);
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->owner = $this->createOwner($this->app);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url): ResponseInterface {
                $this->requests[] = ['method' => $method, 'url' => $url];
                if (str_contains($url, 'microsoftonline')) {
                    return new MockResponse((string) file_get_contents(self::FIXTURES . 'token.json'));
                }
                $answer = $this->answer === null ? null : ($this->answer)($url);

                $fixture = str_contains($url, 'bulk-download') ? 'bulk-download.json' : 'vehicle-with-tests.json';

                return $answer ?? new MockResponse((string) file_get_contents(self::FIXTURES . $fixture));
            },
        ));
        if ($enable) {
            $provider = $this->service($this->app, DvsaProvider::class);
            $secrets = $this->service($this->app, MotHistorySecrets::class);
            foreach (
                [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret-value',
                'api_key' => 'the-api-key-value',
                'token_url' => 'https://login.microsoftonline.com/tenant/oauth2/v2.0/token',
                ] as $slot => $value
            ) {
                $secrets->store($provider, $slot, $value);
            }
            $this->service($this->app, MotHistoryConfig::class)->saveProvider($provider);
        }
    }

    protected function golf(
        string $registration = 'AB12 CDE',
        ?string $vin = null,
        string $make = 'VW',
        string $model = 'Golf',
    ): Vehicle {
        return $this->service($this->app, VehicleService::class)->create($this->owner, new VehicleData(
            VehicleType::Car,
            $make,
            $model,
            FuelType::Petrol,
            registration: $registration,
            vin: $vin,
        ));
    }

    protected function shareWith(Vehicle $vehicle, ShareLevel $level, string $username = 'partner'): TestBrowser
    {
        $member = $this->createMember($this->app, $username);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($vehicle->id, $member->id, $level, false, false, new DateTimeImmutable(self::NOW));

        return $this->browserFor($this->app, $username);
    }

    /**
     * Confirms and fetches as the owner.
     */
    protected function fetch(Vehicle $vehicle, ?TestBrowser $browser = null): TestBrowser
    {
        $browser ??= $this->browserFor($this->app, 'owner');
        $browser->post('/vehicles/' . $vehicle->id . '/mot-history/fetch', ['confirm' => '1']);

        return $browser;
    }

    /**
     * @return list<string> the vehicle URLs DVSA was asked
     */
    protected function vehicleRequests(): array
    {
        return array_values(array_map(
            static fn (array $request): string => $request['url'],
            array_filter($this->requests, static fn (array $request): bool => str_contains($request['url'], '/trade/vehicles/')),
        ));
    }
}
