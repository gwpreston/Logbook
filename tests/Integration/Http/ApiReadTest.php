<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\JsonDoc;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The read endpoints (spec.md §7.20) over a garage with something in every
 * module: each answers its OpenAPI shape (ApiClient checks every response),
 * canonical decimal strings exactly as stored, UTC instants, newest first
 * with cursor paging and since / until, a `display` block in the owner's
 * units, and switched-off modules gone.
 */
final class ApiReadTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['APP_URL' => 'http://localhost:8080', 'FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->owner = $this->owner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * Three fill-ups (the middle one partial), a manual reading, a service
     * with its interval, an MOT expiring soon, an expense and two tyres.
     */
    private function garage(): void
    {
        $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $this->fillUp($this->app, $this->golf, '2026-08-15T17:05:00Z', '40350.5', '20.000', '29.98', true);
        $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40700', '24.5', '36.01');
        $this->reading($this->app, $this->golf, '41000', '2026-09-20T12:00:00Z');
        $schedule = $this->service($this->app, ScheduleService::class)->create($this->golf, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            intervalMonths: 12,
        ));
        $this->service($this->app, MaintenanceService::class)->create($this->golf, new MaintenanceEntryData(
            new DateTimeImmutable('2025-10-10', new DateTimeZone('UTC')),
            MaintenanceCategory::Service,
            'Annual service',
            '187.43',
            '30000',
            'Main dealer',
            scheduleId: $schedule->id,
        ), new DateTimeZone('Europe/London'));
        $this->document($this->app, $this->golf, ComplianceType::Inspection, '2025-10-15', '2026-10-14', '54.85', 'Test centre');
        $this->expense($this->app, $this->golf, '2026-09-05', '12.91');
        $response = $this->browser->post('/vehicles/' . $this->golf->id . '/tyres/fit', [
            'done_on' => '2026-01-10',
            'odometer' => '20000',
            'pos_fl' => '1',
            'pos_fr' => '1',
            'brand' => 'Michelin',
            'model' => 'Primacy 4',
            'tread' => '8',
        ]);
        self::assertSame(303, $response->getStatusCode());
        $this->service($this->app, TripService::class)->create($this->golf, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            '90.5',
            purpose: 'Client visit',
        ));
    }

    public function testEveryEndpointAnswersItsDescribedShape(): void
    {
        $this->garage();
        $id = $this->golf->id;

        foreach (
            ['/me', '/vehicles', '/vehicles?status=all', '/vehicles?status=archived', '/vehicles/' . $id,
            '/vehicles/' . $id . '/summary', '/vehicles/' . $id . '/fuel', '/vehicles/' . $id . '/odometer',
            '/vehicles/' . $id . '/maintenance', '/vehicles/' . $id . '/documents', '/vehicles/' . $id . '/expenses',
            '/vehicles/' . $id . '/tyres', '/upcoming', '/upcoming?vehicle=' . $id, '/reminders',
            '/reminders?vehicle=' . $id . '&status=due', '/vehicles/' . $id . '/trips', '/trips/claim',
            '/trips/claim?year=2025', '/trips/claim?period=custom&from=2026-09-01&to=2026-09-30&vehicles[]=' . $id,
            '/openapi.json'] as $path
        ) {
            $response = $this->api->get($path);
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . self::body($response));
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'), $path);
        }

        self::assertCount(2, ApiClient::json($this->api->get('/vehicles/' . $id . '/tyres'))->doc('items'));
        self::assertNotEmpty(ApiClient::json($this->api->get('/upcoming'))->doc('items')->toArray());
        self::assertNotEmpty(ApiClient::json($this->api->get('/reminders'))->doc('items')->toArray());
        self::assertCount(1, ApiClient::json($this->api->get('/vehicles/' . $id . '/trips'))->doc('items'));
        self::assertCount(1, ApiClient::json($this->api->get('/trips/claim'))->doc('trips'));
    }

    public function testQuantitiesAreCanonicalDecimalStringsAsStored(): void
    {
        $this->garage();

        $fuel = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/fuel'))->doc('items');

        self::assertSame(['2026-09-01T08:00:00Z', '2026-08-15T17:05:00Z', '2026-08-01T07:30:00Z'], $fuel->column('filled_at'));
        $first = $fuel->doc(2);
        self::assertSame('40000.000', $first->get('odometer'));
        self::assertSame('km', $first->get('distance_unit'));
        self::assertSame('42.123', $first->get('volume'));
        self::assertSame('l', $first->get('volume_unit'));
        self::assertSame('61.370', $first->get('total_cost'));
        self::assertSame('GBP', $first->get('currency'));
        self::assertSame('baseline', $first->get('economy', 'status'));
        self::assertNull($first->get('economy', 'segment'));
        self::assertTrue($fuel->get(1, 'is_partial'));
        self::assertSame('40350.500', $fuel->get(1, 'odometer'));

        // The last fill closes a segment over the partial: 44.5 l over 700 km.
        $closing = $fuel->doc(0, 'economy', 'segment');
        self::assertSame('700.000', $closing->get('distance'));
        self::assertSame('44.500', $closing->get('volume'));
        self::assertSame('6.357', $closing->get('consumption'));
        self::assertSame('65.990', $closing->get('cost'));
        self::assertSame('l_per_100km', $fuel->get(0, 'economy', 'consumption_unit'));

        $readings = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/odometer'))->doc('items');
        self::assertSame(['manual', 'fuel', 'fuel', 'fuel', 'tyre', 'maintenance'], $readings->column('source'));
        self::assertSame('41000.000', $readings->get(0, 'odometer'));
        self::assertNull($readings->get(0, 'source_id'));
        self::assertSame($fuel->get(0, 'id'), $readings->get(1, 'source_id'));

        $service = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/maintenance'))->doc('items', 0);
        self::assertSame(
            ['2025-10-10', '187.430', '30000.000'],
            [$service->get('performed_on'), $service->get('cost'), $service->get('odometer')],
        );
        $document = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/documents'))->doc('items', 0);
        self::assertSame(
            ['inspection', '2026-10-14', 'expiring', 15],
            [$document->get('type'), $document->get('expiry_on'), $document->get('status'), $document->get('days_left')],
        );
        $expense = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/expenses'))->doc('items', 0);
        self::assertSame(
            ['2026-09-05', 'parking', '12.910', 'GBP'],
            [$expense->get('spent_on'), $expense->get('category'), $expense->get('amount'), $expense->get('currency')],
        );
    }

    public function testTheBodyHasNoFloats(): void
    {
        $this->garage();

        foreach (['/fuel', '/odometer', '/maintenance', '/documents', '/expenses', '/tyres', '/summary', '/trips'] as $path) {
            $body = self::body($this->api->get('/vehicles/' . $this->golf->id . $path));
            // A JSON number with a decimal point would be a float.
            self::assertDoesNotMatchRegularExpression('/[:\[,]\s*-?\d+\.\d+/', $body, $path);
        }
    }

    public function testListsArePagedByCursorAndFilteredByDateOrInstant(): void
    {
        $this->garage();
        $base = '/vehicles/' . $this->golf->id . '/fuel';

        $first = ApiClient::json($this->api->get($base . '?limit=2'));
        self::assertSame(['2026-09-01T08:00:00Z', '2026-08-15T17:05:00Z'], $first->column('filled_at', 'items'));
        self::assertIsString($first->get('next'));
        self::assertStringStartsWith('http://localhost:8080/api/v1/vehicles/' . $this->golf->id . '/fuel?', $first->get('next'));
        // A fill-up logged meanwhile does not shift the next page.
        $this->fillUp($this->app, $this->golf, '2026-09-25T08:00:00Z', '41200', '30', '45');
        $next = ApiClient::json($this->api->get(substr($first->get('next'), strlen('http://localhost:8080/api/v1'))));
        self::assertSame(['2026-08-01T07:30:00Z'], $next->column('filled_at', 'items'));
        self::assertNull($next->get('next'));

        $august = ApiClient::json($this->api->get($base . '?since=2026-08-01&until=2026-08-31'));
        self::assertSame(['2026-08-15T17:05:00Z', '2026-08-01T07:30:00Z'], $august->column('filled_at', 'items'));
        $instants = ApiClient::json(
            $this->api->get($base . '?since=2026-08-01T08:00:00Z&until=' . rawurlencode('2026-09-01T09:00:00+01:00')),
        );
        self::assertSame(['2026-09-01T08:00:00Z', '2026-08-15T17:05:00Z'], $instants->column('filled_at', 'items'));

        foreach (['limit=0', 'limit=201', 'limit=x', 'cursor=nonsense', 'since=2026-02-30', 'until=yesterday'] as $query) {
            $response = $this->api->get($base . '?' . $query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertSame('invalid_parameter', ApiClient::json($response)->get('code'), $query);
        }
        self::assertSame(400, $this->api->get('/vehicles?status=sold')->getStatusCode());
        self::assertSame(400, $this->api->get('/reminders?status=done')->getStatusCode());
        self::assertSame(404, $this->api->get('/upcoming?vehicle=999999')->getStatusCode());
    }

    public function testTheSummaryCarriesCanonicalFiguresAndTheOwnersDisplay(): void
    {
        $this->garage();

        $summary = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/summary'));

        self::assertSame(
            ['value' => '41000.000', 'recorded_at' => '2026-09-20T12:00:00Z', 'source' => 'manual'],
            $summary->get('odometer'),
        );
        self::assertSame('6.357', $summary->get('fuel', 'liquid', 'average_consumption'));
        self::assertNull($summary->get('fuel', 'electric'));
        self::assertSame('2026-09-01T08:00:00Z', $summary->get('fuel', 'last_fill_up', 'filled_at'));
        self::assertSame(
            ['currency' => 'GBP', 'from' => '2025-10-01', 'to' => '2026-09-29'],
            array_intersect_key($summary->doc('costs')->toArray(), array_flip(['currency', 'from', 'to'])),
        );
        self::assertSame(['overdue' => 0, 'due' => 2], $summary->get('reminders'));
        self::assertSame(['inspection'], $summary->column('type', 'documents'));
        self::assertSame('Annual service', $summary->get('next_due', 'title'));
        self::assertSame('2026-10-10', $summary->get('next_due', 'due_on'));
        // One measurement gives no wear rate yet.
        self::assertSame('unknown', $summary->get('tyres', 'status'));

        // UK preferences: miles and UK mpg, en_GB dates.
        self::assertSame('25,476 mi', $summary->get('display', 'odometer'));
        self::assertSame('44.4 mpg', $summary->get('display', 'economy'));
        self::assertSame('1 Sept 2026, 09:00', $summary->get('display', 'last_fill_up'));
        self::assertSame('Annual service · 10 Oct 2026', $summary->get('display', 'next_due'));
    }

    public function testDisplayFollowsTheOwnersUnits(): void
    {
        $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', '40000', '40', '60');
        $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40800', '48', '72');

        $expected = [
            'metric' => [UnitPreset::Metric, 'en', '40,800 km', '6.0 L/100 km'],
            'us' => [UnitPreset::Us, 'en_US', '25,352 mi', '39.2 mpg (US)'],
        ];
        foreach ($expected as $case => [$preset, $locale, $odometer, $economy]) {
            $preferences = new DisplayPreferences(
                $locale,
                'America/New_York',
                $preset->distance(),
                $preset->volume(),
                $preset->consumption(),
                'USD',
                depthUnit: $preset->depth(),
            );
            $this->service($this->app, UserRepository::class)
                ->updateProfile($this->owner->id, 'Pat Owner', $preferences, new DateTimeImmutable());

            $display = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/summary'))->doc('display');

            self::assertSame($odometer, $display->get('odometer'), $case);
            self::assertSame($economy, $display->get('economy'), $case);
        }
        // Canonical figures never change with the preferences.
        $summary = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/summary'));
        self::assertSame('40800.000', $summary->get('odometer', 'value'));
        self::assertSame('6.000', $summary->get('fuel', 'liquid', 'average_consumption'));
    }

    public function testAnElectricVehicleReportsKwh(): void
    {
        $ev = $this->vehicle($this->app, 'Kia', 'Niro EV', null, FuelType::Electric);
        $this->fillUp($this->app, $ev, '2026-08-01T07:30:00Z', '10000', '50', '12.50');
        $this->fillUp($this->app, $ev, '2026-09-01T08:00:00Z', '10300', '45', '11.25');

        $summary = ApiClient::json($this->api->get('/vehicles/' . $ev->id . '/summary'));
        $fill = ApiClient::json($this->api->get('/vehicles/' . $ev->id . '/fuel'))->doc('items', 0);

        self::assertNull($summary->get('fuel', 'liquid'));
        self::assertSame('kwh_per_100km', $summary->get('fuel', 'electric', 'consumption_unit'));
        self::assertSame('15.000', $summary->get('fuel', 'electric', 'average_consumption'));
        self::assertSame(['electric', 'kwh', 'ev'], [$fill->get('energy'), $fill->get('volume_unit'), $fill->get('fuel')]);
        // 45 kWh over 300 km is 15 kWh/100 km: 4.1 mi/kWh for a miles owner.
        self::assertSame('4.1 mi/kWh', $summary->get('display', 'economy'));
    }

    public function testASwitchedOffModulesEndpointsAndFieldsAreGone(): void
    {
        $this->garage();
        // Only Reports stays on.
        $this->service($this->app, FeatureToggles::class)->save([Feature::Reports]);
        $id = $this->golf->id;

        foreach (
            ['/vehicles/' . $id . '/fuel', '/vehicles/' . $id . '/maintenance', '/vehicles/' . $id . '/documents',
            '/vehicles/' . $id . '/tyres', '/reminders', '/vehicles/' . $id . '/trips', '/trips/claim'] as $path
        ) {
            self::assertSame(404, $this->api->get($path)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->api->post('/vehicles/' . $id . '/fuel', ['odometer' => '1'])->getStatusCode());
        self::assertSame(404, $this->api->post('/vehicles/' . $id . '/trips', ['from' => 'A'])->getStatusCode());

        $summary = ApiClient::json($this->api->get('/vehicles/' . $id . '/summary'));
        foreach (['fuel', 'reminders', 'documents', 'tyres'] as $field) {
            self::assertArrayNotHasKey($field, $summary->toArray(), $field);
        }
        self::assertArrayNotHasKey('economy', $summary->doc('display')->toArray());
        self::assertSame(200, $this->api->get('/vehicles/' . $id . '/odometer')->getStatusCode(), 'the odometer is core');
    }

    public function testArchivedVehiclesAreListedOnRequest(): void
    {
        $mondeo = $this->vehicle($this->app, 'Ford', 'Mondeo');
        $this->service($this->app, VehicleService::class)->archive($this->owner, $mondeo);

        $names = static fn (JsonDoc $list): array => $list->column('name', 'items');
        self::assertSame(['Volkswagen Golf'], $names(ApiClient::json($this->api->get('/vehicles'))));
        self::assertSame(['Ford Mondeo'], $names(ApiClient::json($this->api->get('/vehicles?status=archived'))));
        self::assertSame(['Volkswagen Golf', 'Ford Mondeo'], $names(ApiClient::json($this->api->get('/vehicles?status=all'))));
        self::assertSame('archived', ApiClient::json($this->api->get('/vehicles/' . $mondeo->id))->get('status'));
    }

    public function testTheDescriptionIsServedForThisInstallWithoutAKey(): void
    {
        $response = $this->api($this->app, null)->get('/openapi.json');

        self::assertSame(200, $response->getStatusCode());
        $document = ApiClient::json($response);
        self::assertSame('3.1.0', $document->get('openapi'));
        self::assertSame('http://localhost:8080/api/v1', $document->get('servers', 0, 'url'));
    }
}
