<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\SetChoice;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The shapes of the edges (spec.md §7.20): a vehicle with one fill-up, one
 * with every detail set, a plug-in hybrid, a distance interval, a document
 * with its odometer, a manual reminder, and tyres stored in a set, retired
 * and worn. Every GET answers its OpenAPI shape (ApiClient checks), and
 * every decimal has its fixed number of places, zero included.
 */
final class ApiEdgeCasesTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** A decimal the contract allows: digits, a point, digits. */
    private const string DECIMAL = '/^-?\d+\.\d+$/';

    /** @var App<ContainerInterface> */
    private App $app;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($this->app);
        $owner = $this->createOwner($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testEveryEndpointOverTheEdges(): void
    {
        $vehicles = [$this->oneFill(), $this->detailed(), $this->plugInHybrid(), $this->serviced()];

        $paths = ['/vehicles?status=all', '/upcoming', '/reminders'];
        foreach ($vehicles as $vehicle) {
            foreach (['', '/summary', '/fuel', '/odometer', '/maintenance', '/documents', '/expenses', '/tyres'] as $suffix) {
                $paths[] = '/vehicles/' . $vehicle->id . $suffix;
            }
            $paths[] = '/upcoming?vehicle=' . $vehicle->id;
        }

        foreach ($paths as $path) {
            $response = $this->api->get($path);
            // ApiClient checks the shape, every decimal's pattern (a point and places) included.
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . self::body($response));
        }
    }

    public function testZeroesAndSingleFillsKeepTheirPlaces(): void
    {
        $polo = $this->oneFill();

        $summary = ApiClient::json($this->api->get('/vehicles/' . $polo->id . '/summary'));

        self::assertSame('0.000', $summary->get('fuel', 'liquid', 'measured_distance'));
        self::assertNull($summary->get('fuel', 'liquid', 'average_consumption'));
        self::assertSame('baseline', $summary->get('fuel', 'last_fill_up', 'economy', 'status'));
        self::assertMatchesRegularExpression(self::DECIMAL, $summary->string('odometer', 'value'));
    }

    public function testTyresStoredRetiredAndWorn(): void
    {
        $vehicle = $this->serviced();

        $tyres = ApiClient::json($this->api->get('/vehicles/' . $vehicle->id . '/tyres'));

        self::assertSame(['fitted', 'fitted', 'stored', 'retired'], $tyres->column('status', 'items'));
        self::assertSame(['rl', 'rr', null, null], $tyres->column('position', 'items'));
        $worn = $tyres->doc('items', 0);
        self::assertTrue($worn->get('tread', 'worn'));
        self::assertSame('2.500', $worn->get('tread', 'latest', 'depth'));
        $stored = $tyres->doc('items', 2);
        self::assertIsInt($stored->get('set_id'));
        self::assertSame(
            ['3222', '2022-05-30', 'winter'],
            [$stored->get('dot'), $stored->get('manufactured_on'), $stored->get('season')],
        );
        self::assertSame('worn', $tyres->get('items', 3, 'retired_reason'));
        self::assertSame('2026-06-01', $tyres->get('items', 3, 'retired_on'));
    }

    public function testDetailsDistanceIntervalsDocumentsAndManualReminders(): void
    {
        $detailed = $this->detailed();
        $serviced = $this->serviced();

        $vehicle = ApiClient::json($this->api->get('/vehicles/' . $detailed->id));
        self::assertSame(['50.000', 'l', 'EUR', 'EUR', '12500.000', '9000.500'], [
            $vehicle->get('capacity'), $vehicle->get('capacity_unit'), $vehicle->get('currency'),
            $vehicle->get('currency_override'), $vehicle->get('purchase_price'), $vehicle->get('sale_price'),
        ]);
        self::assertSame(['GTI', 'e5_98', '2019-03-14', 'WVWZZZ1KZ9W000001'], [
            $vehicle->get('variant'), $vehicle->get('default_grade'), $vehicle->get('first_registered_on'), $vehicle->get('vin'),
        ]);

        $upcoming = ApiClient::json($this->api->get('/upcoming?vehicle=' . $serviced->id));
        $oil = array_search('Oil change', $upcoming->column('title', 'items'), true);
        self::assertIsInt($oil, 'the oil change is coming up');
        self::assertSame('35000.000', $upcoming->get('items', $oil, 'due_odometer'));
        self::assertTrue($upcoming->get('items', $oil, 'projected'), 'its date is estimated from the daily distance');

        $document = ApiClient::json($this->api->get('/vehicles/' . $serviced->id . '/documents'))->doc('items', 0);
        self::assertSame('21000.500', $document->get('odometer'));
        $reminders = ApiClient::json($this->api->get('/reminders?vehicle=' . $serviced->id));
        self::assertContains('manual', $reminders->column('source', 'items'));
    }

    private function oneFill(): Vehicle
    {
        $polo = $this->vehicle($this->app, 'Volkswagen', 'Polo');
        $this->fillUp($this->app, $polo, '2026-09-01T08:00:00Z', '5000', '30', '45');

        return $polo;
    }

    private function detailed(): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create($this->owner($this->app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            nickname: 'Rocket',
            year: 2019,
            registration: 'RO19 CKT',
            vin: 'WVWZZZ1KZ9W000001',
            capacity: '50',
            currency: 'EUR',
            purchaseDate: self::day('2019-04-01'),
            purchasePrice: '12500',
            saleDate: self::day('2026-09-01'),
            salePrice: '9000.5',
            defaultGrade: FuelGrade::E5_98,
            variant: 'GTI',
            firstRegisteredOn: self::day('2019-03-14'),
        ));
    }

    private function plugInHybrid(): Vehicle
    {
        $phev = $this->vehicle($this->app, 'Mitsubishi', 'Outlander', null, FuelType::Phev);
        $this->fillUp($this->app, $phev, '2026-08-01T08:00:00Z', '10000', '40', '60');
        $this->fillUp($this->app, $phev, '2026-08-02T20:00:00Z', '10040', '12', '3', grade: FuelGrade::Home);
        $this->fillUp($this->app, $phev, '2026-08-20T08:00:00Z', '10600', '35', '52.5');
        $this->fillUp($this->app, $phev, '2026-08-21T20:00:00Z', '10650', '13', '3.25', grade: FuelGrade::Home);

        return $phev;
    }

    /**
     * An oil change every 15,000 km, a document read at the odometer, a manual
     * reminder, and tyres: two fitted (one worn), one stored in a set, one retired.
     */
    private function serviced(): Vehicle
    {
        $car = $this->vehicle($this->app, 'Skoda', 'Octavia', null, FuelType::Diesel);
        $zone = new DateTimeZone('Europe/London');
        $this->reading($this->app, $car, '20000', '2026-01-01T08:00:00Z');
        // 10,000 km in eight months: the next oil change (35,000 km) is a few months away.
        $this->reading($this->app, $car, '30000', '2026-09-01T08:00:00Z');
        $schedule = $this->service($this->app, ScheduleService::class)->create($car, new MaintenanceScheduleData(
            MaintenanceCategory::Oil,
            'Oil change',
            intervalKm: '15000.000',
        ));
        $this->service($this->app, MaintenanceService::class)->create($car, new MaintenanceEntryData(
            self::day('2026-01-05'),
            MaintenanceCategory::Oil,
            'Oil change',
            '0',
            '20000',
            scheduleId: $schedule->id,
        ), $zone);
        $this->service($this->app, ComplianceService::class)->create($car, new ComplianceDocumentData(
            ComplianceType::Inspection,
            null,
            'Test centre',
            'MOT-1',
            self::day('2026-03-01'),
            self::day('2027-02-28'),
            '0',
            odometerKm: '21000.5',
        ), $zone);
        $this->service($this->app, ReminderService::class)->createManual(
            $this->owner($this->app),
            new ManualReminderData($car->id, 'Wash', self::day('2026-10-05'), 7),
        );

        $tyres = $this->service($this->app, TyreChangeService::class);
        $dot = DotCode::fromStored('3222', self::day('2022-05-30'));
        $winter = new TyreData('Michelin', 'Alpin 6', '205/55 R16 91H', TyreSeason::Winter, $dot);
        $existing = $tyres->existing($car, new TyreChangeData(self::day('2026-01-02'), '20000'), [
            new NewTyre(TyrePosition::FrontLeft, $winter, '7.0'),
            new NewTyre(TyrePosition::FrontRight, $winter, '7.0'),
            new NewTyre(TyrePosition::RearLeft, new TyreData('Pirelli', 'P7'), '2.5'),
            new NewTyre(TyrePosition::RearRight, new TyreData('Pirelli', 'P7'), '6.0'),
        ], $zone, 'en_GB');
        [$frontLeft, $frontRight] = $existing->tyreIds();
        $tyres->remove(
            $car,
            new TyreChangeData(self::day('2026-06-01'), '24000'),
            [$frontLeft => TyreRetireReason::Worn, $frontRight => null],
            new SetChoice(newSet: new TyreSetData('Winter wheels', 'Garage')),
            null,
            $zone,
            'en_GB',
        );

        return $car;
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
