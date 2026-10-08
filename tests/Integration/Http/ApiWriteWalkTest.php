<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * Every API write, walked from the route list (Phase 39.2, spec.md §7.20):
 * a read key changes nothing (403 `insufficient_scope`), and an archived
 * vehicle refuses every write (409 `vehicle_archived`) but *Restore* and a
 * valuation. A new write route is covered without touching this test.
 */
final class ApiWriteWalkTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @return list<RouteInterface>
     */
    private function writes(): array
    {
        return array_values(array_filter(
            $this->app->getRouteCollector()->getRoutes(),
            static fn (RouteInterface $route): bool => str_starts_with($route->getPattern(), '/api/')
                && array_diff($route->getMethods(), ['GET']) !== [],
        ));
    }

    /**
     * @param array<string, int> $ids placeholder → id (1 when not named)
     */
    private function call(ApiClient $api, RouteInterface $route, string $method, array $ids): ResponseInterface
    {
        $path = substr((string) preg_replace_callback(
            '/\{([a-z]+):[^}]+\}/',
            static fn (array $m): string => (string) ($ids[(string) $m[1]] ?? 1),
            $route->getPattern(),
        ), strlen('/api/v1'));

        return match ($method) {
            'PATCH' => $api->patch($path, ['notes' => 'x']),
            'PUT' => $api->put($path),
            'DELETE' => $api->delete($path),
            default => $api->post($path, ['amount' => '1']),
        };
    }

    public function testAReadKeyChangesNothing(): void
    {
        $reader = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        $routes = $this->writes();
        self::assertGreaterThan(40, count($routes));

        foreach ($routes as $route) {
            foreach (array_diff($route->getMethods(), ['GET']) as $method) {
                $response = $this->call($reader, $route, $method, ['id' => $this->golf->id]);
                self::assertSame(403, $response->getStatusCode(), $method . ' ' . $route->getPattern());
                self::assertSame('insufficient_scope', ApiClient::json($response)->get('code'));
            }
        }
    }

    public function testAnArchivedVehicleRefusesEveryWriteButRestoreAndAValuation(): void
    {
        $base = '/vehicles/' . $this->golf->id;
        $ids = ['id' => $this->golf->id];
        $ids['entry'] = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '10000', '40', '55')->id;
        $agreement = ApiClient::json($this->api->post($base . '/finance/agreements', [
            'type' => 'hp', 'lender' => 'Black Horse', 'started_on' => '2024-01-15', 'first_payment_on' => '2024-02-15',
            'number_of_payments' => 48, 'regular_payment' => '301.35', 'cash_price' => 15000,
        ]));
        $ids['agreement'] = $agreement->int('entry', 'id');
        $payment = $base . '/finance/agreements/' . $ids['agreement'] . '/payments';
        $ids['event'] = ApiClient::json($this->api->post($payment, ['kind' => 'missed', 'due_on' => '2026-08-15']))
            ->int('entry', 'events', 0, 'id');
        $quote = $base . '/finance/agreements/' . $ids['agreement'] . '/quotes';
        $ids['quote'] = ApiClient::json($this->api->post($quote, [
            'quoted_on' => '2026-09-01', 'amount' => '7000', 'valid_until' => '2026-09-30',
        ]))->int('entry', 'quotes', 0, 'id');
        $change = $this->api->post($base . '/tyres/changes', ['kind' => 'existing', 'odometer' => 10000, 'tyres' => [
            ['position' => 'fl', 'brand' => 'Goodyear'],
        ]]);
        $ids['change'] = ApiClient::json($change)->int('entry', 'id');
        $tyres = ApiClient::json($this->api->get($base . '/tyres'));
        $ids['tyre'] = $tyres->int('items', 0, 'id');
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);

        $walked = 0;
        foreach ($this->writes() as $route) {
            if (!VehicleAccessMiddleware::isVehicleRoute($route) || !str_contains($route->getPattern(), '{id:')) {
                continue;
            }
            foreach (array_diff($route->getMethods(), ['GET']) as $method) {
                $name = (string) $route->getName();
                $label = $method . ' ' . $route->getPattern();
                if (str_contains($name, 'valuations')) {
                    continue; // The one write an archived vehicle takes (§7.1); ApiVehicleFiguresTest covers it.
                }
                // `{entry}` is the fill-up here; every list's own archived refusal is tested with its edits.
                if (
                    in_array($route->getArgument('list'), ['schedules', 'odometer', 'maintenance', 'documents',
                    'expenses', 'trips', 'incidents'], true)
                ) {
                    continue;
                }
                $response = $this->call($this->api, $route, $method, $ids);
                if ($name === 'api.vehicles.restore') {
                    self::assertSame(200, $response->getStatusCode(), $label);
                    $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
                    continue;
                }
                self::assertSame(409, $response->getStatusCode(), $label . ': ' . self::body($response));
                self::assertSame('vehicle_archived', ApiClient::json($response)->get('code'), $label);
                $walked++;
            }
        }
        self::assertGreaterThan(15, $walked);
    }
}
