<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use Closure;
use DateTimeImmutable;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\User\User;
use Logbook\Repository\StationRepository;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\FuelPriceSecrets;
use Logbook\Service\FuelPrices\FuelPriceSettings;
use Logbook\Service\FuelPrices\FuelPricesJob;
use Logbook\Service\FuelPrices\Pause;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Station\PlaceService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\MutableClock;
use DI\Container;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared set-up for the fuel price tests (spec.md §7.34): an app with the
 * UK Fuel Finder feed answered from the fixture (tests/Fixtures/fuel-finder),
 * no waiting between requests, and every request recorded. Nothing leaves
 * the machine.
 */
abstract class FuelPricesTestCase extends AppTestCase
{
    use CostFixtures;

    protected const string NOW = '2026-10-03T07:00:00Z';
    protected const string TOKEN = 'tok-0123456789abcdef';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    protected array $requests = [];
    /** @var Closure(string, string, array<string, mixed>): ?ResponseInterface|null answers a request, or null for the fixture */
    protected ?Closure $answer = null;
    protected MutableClock $clock;
    /** @var list<mixed>|null stations served instead of the fixture */
    protected ?array $stations = null;
    /** @var list<mixed>|null prices served instead of the fixture */
    protected ?array $prices = null;

    /**
     * @return array{App<ContainerInterface>, User}
     */
    protected function pricesApp(bool $enable = true): array
    {
        $app = $this->createApp([
            'FF_ID' => 'client-id-1234',
            'FF_SECRET' => 'client-secret-5678',
            'SESSION_SECRET' => 'a-session-secret-for-sealing-credentials-in-tests',
        ]);
        $this->resetDatabase($app);
        $this->clock = $this->pinClock($app, self::NOW);
        $owner = $this->createOwner($app);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(Pause::class, new class () implements Pause {
            public function seconds(float $seconds): void
            {
            }
        });
        $container->set(HttpClientInterface::class, new MockHttpClient(
            fn (string $method, string $url, array $options): ResponseInterface => $this->respond($method, $url, $options),
        ));
        if ($enable) {
            $this->enable($app);
        }

        return [$app, $owner];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function enable(App $app): void
    {
        $provider = $this->service($app, FuelFinderProvider::class);
        $secrets = $this->service($app, FuelPriceSecrets::class);
        $secrets->store($provider, 'client_id', 'env:FF_ID');
        $secrets->store($provider, 'client_secret', 'env:FF_SECRET');
        $this->service($app, FuelPriceConfig::class)->save(new FuelPriceSettings(FuelFinderProvider::CODE));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function sync(App $app): JobRun
    {
        return $this->service($app, JobRunner::class)->run($this->service($app, FuelPricesJob::class), JobTrigger::Manual);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function station(
        App $app,
        string $name,
        ?string $lat = null,
        ?string $lon = null,
        ?string $postcode = null,
    ): Station {
        $repository = $this->service($app, StationRepository::class);
        $id = $repository->insert(
            new StationData($name, postcode: $postcode, latitude: $lat, longitude: $lon),
            $this->owner($app)->id,
            new DateTimeImmutable(self::NOW),
        );

        return $repository->find($id) ?? self::fail('station not saved');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function home(
        App $app,
        User $user,
        string $lat = '54.716000',
        string $lon = '-6.208000',
        string $name = 'Home',
    ): void {
        $this->service($app, PlaceService::class)->create($user, new PlaceData($name, $lat, $lon));
    }

    /**
     * The fixture's id for a station (sha256 of its seed).
     */
    protected static function ref(string $seed): string
    {
        return hash('sha256', $seed);
    }

    /**
     * @return list<mixed>
     */
    protected static function fixture(string $file): array
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/fuel-finder/' . $file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($data);

        return array_values($data);
    }

    /**
     * @param array<mixed> $options
     */
    private function respond(string $method, string $url, array $options): ResponseInterface
    {
        $named = [];
        foreach ($options as $key => $value) {
            $named[(string) $key] = $value;
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $named];
        if ($this->answer !== null) {
            $custom = ($this->answer)($method, $url, $named);
            if ($custom !== null) {
                return $custom;
            }
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match ($path) {
            '/api/v1/oauth/generate_access_token' => new MockResponse(json_encode([
                'success' => true,
                'data' => ['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 3600],
            ], JSON_THROW_ON_ERROR)),
            '/api/v1/pfs' => new MockResponse(
                json_encode($this->stations ?? self::fixture('pfs-page-1.json'), JSON_THROW_ON_ERROR),
            ),
            '/api/v1/pfs/fuel-prices' => new MockResponse(
                json_encode($this->prices ?? self::fixture('fuel-prices-page-1.json'), JSON_THROW_ON_ERROR),
            ),
            default => new MockResponse('{}', ['http_code' => 404]),
        };
    }

    /**
     * The query of the recorded GET requests to a path.
     *
     * @return list<array<string, string>>
     */
    protected function queries(string $path): array
    {
        $found = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'GET' && parse_url($request['url'], PHP_URL_PATH) === $path) {
                parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
                $values = [];
                foreach ($query as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $values[$key] = $value;
                    }
                }
                $found[] = $values;
            }
        }

        return $found;
    }
}
