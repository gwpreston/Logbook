<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Api\ApiIncidents;
use Logbook\Service\Api\ApiIssues;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ConfigurableVehicleAccess;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\VehicleRoutes;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * A key has exactly its user's access (spec.md §7.20, §5): with the
 * configurable policy, no access is a 404 on every API vehicle route and
 * absent from every list, view only refuses the writes, no costs leaves
 * every amount out of every response, and another owner's vehicles never
 * show.
 */
final class ApiAccessTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;
    use VehicleRoutes;

    /** Amounts no other figure in these responses can produce by accident. */
    private const array AMOUNTS = ['14250.370', '61.370', '187.430', '243.190', '12.910', '55.000'];

    /** The table of each list with a single-entry read (Phase 39.1). */
    private const array ENTRY_TABLES = [
        'fuel' => 'fuel_entries',
        'odometer' => 'odometer_readings',
        'maintenance' => 'maintenance_entries',
        'documents' => 'compliance_documents',
        'expenses' => 'expense_entries',
        'trips' => 'trips',
        'incidents' => 'incidents',
        'issues' => 'issues',
        'schedules' => 'maintenance_schedules',
        'valuations' => 'vehicle_valuations',
    ];

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testWithoutAccessEveryApiVehicleRouteIsNotFound(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->costlyGolf($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $access->set($golf);

        $routes = $this->apiVehicleRoutes($app);
        self::assertGreaterThanOrEqual(10, count($routes));
        foreach ($routes as $route) {
            self::assertSame(404, $this->callRoute($app, $api, $route, $golf)->getStatusCode(), $route->getPattern());
        }
        self::assertSame([], ApiClient::json($api->get('/vehicles?status=all'))->get('items'));
        self::assertSame([], ApiClient::json($api->get('/upcoming'))->get('items'));
        self::assertSame(404, $api->get('/upcoming?vehicle=' . $golf->id)->getStatusCode());
        self::assertSame(404, $api->get('/reminders?vehicle=' . $golf->id)->getStatusCode());
    }

    public function testViewOnlyReadsButRefusesTheWrites(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->costlyGolf($app);
        $api = $this->api($app, $this->apiKey($app, $owner));
        $access->set($golf, VehicleAbility::View);

        foreach ($this->apiVehicleRoutes($app) as $route) {
            $needs = VehicleAbility::from($route->getArgument(VehicleAccessMiddleware::ABILITY) ?? '');
            $status = $this->callRoute($app, $api, $route, $golf)->getStatusCode();
            if (in_array($route->getName(), ['api.finance.show', 'api.finance.agreements'], true)) {
                // Finance needs Manage and ViewCosts and answers 404 to anyone else (spec.md §7.32 *Access*).
                self::assertSame(404, $status, $route->getPattern());
            } elseif ($route->getName() === 'api.vehicles.photo') {
                // Phase 39.3: View reaches the photo, and the fixture has none.
                self::assertSame(404, $status, $route->getPattern());
            } elseif ($route->getName() === 'api.mot_tests') {
                // Phase 41: MOT history is off in the fixture (spec.md §7.38); View reaches it once on (MotElsewhereTest).
                self::assertSame(404, $status, $route->getPattern());
            } elseif ($needs === VehicleAbility::View) {
                self::assertSame(200, $status, $route->getPattern());
            } else {
                self::assertSame(403, $status, $route->getPattern() . ' needs ' . $needs->value);
            }
        }
        $refused = ApiClient::json($api->post('/vehicles/' . $golf->id . '/fuel', ['odometer' => '1']));
        self::assertSame('forbidden', $refused->get('code'));
    }

    public function testWithoutCostsNoAmountIsInAnyResponse(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->costlyGolf($app);
        $api = $this->api($app, $this->apiKey($app, $owner));

        $id = $golf->id;
        $paths = ['/vehicles', '/vehicles/' . $id, '/vehicles/' . $id . '/summary', '/vehicles/' . $id . '/fuel',
            '/vehicles/' . $id . '/maintenance', '/vehicles/' . $id . '/documents', '/vehicles/' . $id . '/tyres',
            '/upcoming', '/reminders'];
        $all = implode("\n", array_map(fn (string $path): string => self::body($api->get($path)), $paths));
        foreach (self::AMOUNTS as $amount) {
            self::assertStringContainsString(
                $amount,
                $all . self::body($api->get('/vehicles/' . $id . '/expenses')),
                'the fixture shows ' . $amount . ' with costs',
            );
        }

        $access->except($golf, VehicleAbility::ViewCosts);
        $all = implode("\n", array_map(fn (string $path): string => self::body($api->get($path)), $paths));
        foreach (self::AMOUNTS as $amount) {
            self::assertStringNotContainsString($amount, $all, $amount);
        }
        $fields = ['"total_cost"', '"price_per_unit"', '"purchase_price"', '"cost"', '"costs"', '"currency":"GBP","price'];
        foreach ($fields as $field) {
            self::assertStringNotContainsString($field, $all, $field . ' is left out, not zeroed');
        }
        self::assertSame(403, $api->get('/vehicles/' . $id . '/expenses')->getStatusCode(), 'expenses need costs');
        self::assertArrayNotHasKey(
            'cost_per_distance',
            ApiClient::json($api->get('/vehicles/' . $id . '/summary'))->doc('display')->toArray(),
        );
        self::assertSame(
            201,
            $api->post('/vehicles/' . $id . '/odometer', ['odometer' => '9000', 'distance_unit' => 'km'])->getStatusCode(),
            'logging needs no costs',
        );
    }

    public function testAnotherOwnersVehiclesNeverShowAndAGrantedOneDoes(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $this->costlyGolf($app);
        $other = $this->otherOwner($app);
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Secret', 'Car', FuelType::Diesel));
        $api = $this->api($app, $this->apiKey($app, $owner));
        $theirApi = $this->api($app, $this->apiKey($app, $other));

        self::assertSame(['Volkswagen Golf'], ApiClient::json($api->get('/vehicles?status=all'))->column('name', 'items'));
        self::assertSame(404, $api->get('/vehicles/' . $theirs->id)->getStatusCode());
        self::assertSame(
            ['Secret Car'],
            ApiClient::json($theirApi->get('/vehicles'))->column('name', 'items'),
            'their key sees theirs only',
        );

        $access->set($theirs, VehicleAbility::View, VehicleAbility::Log);
        self::assertSame(['Secret Car', 'Volkswagen Golf'], ApiClient::json($api->get('/vehicles'))->column('name', 'items'));
        self::assertSame(
            201,
            $api->post('/vehicles/' . $theirs->id . '/odometer', ['odometer' => '5000', 'distance_unit' => 'km'])
                ->getStatusCode(),
            'a granted Log writes',
        );
        self::assertArrayNotHasKey(
            'purchase_price',
            ApiClient::json($api->get('/vehicles/' . $theirs->id))->toArray(),
            'no ViewCosts granted',
        );
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: ConfigurableVehicleAccess}
     */
    private function appWithPolicy(): array
    {
        // Trips are off by default; their API routes are vehicle routes too.
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $access = new ConfigurableVehicleAccess($this->service($app, VehicleRepository::class));
        $container->set(VehicleAccess::class, $access);

        return [$app, $access];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function callRoute(App $app, ApiClient $api, RouteInterface $route, Vehicle $vehicle): ResponseInterface
    {
        $path = substr(str_replace('{id:[0-9]+}', (string) $vehicle->id, $route->getPattern()), strlen('/api/v1'));
        if (str_contains($path, '/{entry:[0-9]+}')) {
            // A single-entry read (Phase 39.1): an entry of that list, found on the owner's side
            // so every access level really requests it; the fixture has one of each.
            $list = (string) $route->getArgument('list');
            $table = self::ENTRY_TABLES[$list] ?? null;
            self::assertNotNull($table, 'no table for the ' . $list . ' list');
            $id = $this->connection($app)->createQueryBuilder()
                ->select('MIN(id)')
                ->from($table)
                ->where('vehicle_id = :vehicle')
                ->setParameter('vehicle', $vehicle->id)
                ->fetchOne();
            self::assertTrue(is_int($id) || is_string($id), 'the fixture has no ' . $list . ' entry');
            $path = str_replace('{entry:[0-9]+}', (string) $id, $path);
        }

        // Other ids (a finance agreement, event or quote): any, the access check comes first.
        $path = (string) preg_replace('/\{[a-z]+:\[0-9\]\+\}/', '1', $path);

        return match ($route->getMethods()[0]) {
            'GET' => $api->get($path),
            // Phase 39.2: edits and deletes, refused before anything is read from the body.
            'PATCH' => $api->patch($path, ['notes' => 'x']),
            'PUT' => $api->put($path),
            'DELETE' => $api->delete($path),
            default => $api->post(
                $path,
                ['odometer' => '12000', 'distance_unit' => 'km', 'volume' => '40', 'total_cost' => '60'],
            ),
        };
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function costlyGolf(App $app): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'GO19 ABC',
            purchaseDate: new DateTimeImmutable('2025-03-01', new DateTimeZone('UTC')),
            purchasePrice: '14250.37',
        ));
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '55.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '44.5', '61.37');
        $this->maintenance($app, $golf, '2026-09-05', 'Annual service', '187.43', '10200');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '243.19');
        $this->expense($app, $golf, '2026-09-12', '12.91', ExpenseCategory::Parking);
        // One of every list with a single-entry read (Phase 39.1).
        $this->service($app, ScheduleService::class)->create($golf, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            intervalMonths: 12,
        ));
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            '11000',
        ));
        $this->service($app, TripService::class)->create($golf, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            '90.5',
        ));
        $this->service($app, ApiIncidents::class)->log($this->owner($app), $golf, [
            'occurred_on' => '2026-03-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'damage_areas' => ['rear'],
        ]);
        $this->service($app, ApiIssues::class)->log($this->owner($app), $golf, [
            'noticed_on' => '2026-08-12',
            'title' => 'Knock from front left',
        ]);
        // Added by someone else: a user's own entries always carry their amounts (Phase 19).
        $author = $this->createMember($app, 'author');
        foreach (['fuel_entries', 'maintenance_entries', 'compliance_documents', 'expense_entries'] as $table) {
            $this->connection($app)->update($table, ['created_by' => $author->id], ['vehicle_id' => $golf->id]);
        }

        return $golf;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function otherOwner(App $app): User
    {
        return $this->service($app, UserRepository::class)->insert(
            'other',
            $this->service($app, PasswordHasher::class)->hash(self::PASSWORD),
            'Sam Other',
            DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
    }
}
