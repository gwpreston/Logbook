<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Tyre\NewTyre;
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
 * The Phase 26.3 writes (spec.md §7.20 *More write endpoints*): service
 * records, documents, expenses, tread checks and manual reminders, through
 * their forms' parsers and services, with the forms' abilities, safe retries
 * and module gating. Every response is checked against openapi.json.
 */
final class ApiMoreWritesTest extends AppTestCase
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
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($this->app);
        // UK preferences: miles, litres, millimetres.
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    private function path(string $rest): string
    {
        return '/vehicles/' . $this->golf->id . '/' . $rest;
    }

    private function rows(string $table): int
    {
        $count = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM ' . $table);

        return is_numeric($count) ? (int) $count : -1;
    }

    public function testAServiceRecordWritesItsReadingAndARetryIsTheSameOne(): void
    {
        $body = [
            'performed_on' => '2026-09-20',
            'odometer' => '30280',
            'category' => 'service',
            'title' => 'Annual service',
            'cost' => '187.43',
            'vendor' => 'Kwik Fit',
        ];
        $response = $this->api->post($this->path('maintenance'), $body);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame(
            ['48730.936', '187.430', 'GBP'],
            [$entry->get('odometer'), $entry->get('cost'), $entry->get('currency')],
        );
        self::assertSame(1, $this->rows('odometer_readings'), 'the record writes its reading, as the form');

        $retry = $this->api->post($this->path('maintenance'), ['vendor' => 'Someone else'] + $body);
        self::assertSame(200, $retry->getStatusCode());
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertSame(1, $this->rows('maintenance_entries'));
    }

    public function testFormMessagesAndUnknownFieldsUseTheApiNames(): void
    {
        $response = $this->api->post(
            $this->path('maintenance'),
            ['category' => 'service', 'schedule_id' => 99, 'colour' => 'red'],
        );
        self::assertSame(422, $response->getStatusCode());
        $errors = ApiClient::json($response)->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('colour', $errors);

        $response = $this->api->post($this->path('maintenance'), ['category' => 'service', 'schedule_id' => 99]);
        $errors = ApiClient::json($response)->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('title', $errors, 'required, as on the form');
        self::assertArrayHasKey('schedule_id', $errors, 'not one of the vehicle\'s schedules');
    }

    public function testADocumentWithAnOdometerNeedsAStartAsTheFormSays(): void
    {
        $response = $this->api->post($this->path('documents'), ['type' => 'inspection', 'odometer' => '30000']);
        self::assertSame(422, $response->getStatusCode());
        $errors = ApiClient::json($response)->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('odometer', $errors);

        $body = [
            'type' => 'inspection',
            'provider' => 'Halfords',
            'start_on' => '2026-09-20',
            'expiry_on' => '2027-09-19',
            'cost' => '54.85',
        ];
        $response = $this->api->post($this->path('documents'), $body);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame(['inspection', '2027-09-19', 'valid'], [
            ApiClient::json($response)->get('entry', 'type'),
            ApiClient::json($response)->get('entry', 'expiry_on'),
            ApiClient::json($response)->get('entry', 'status'),
        ]);
        self::assertSame(200, $this->api->post($this->path('documents'), $body)->getStatusCode());
    }

    public function testAnExpenseOfZeroIsValidAndDefaultsToToday(): void
    {
        $response = $this->api->post($this->path('expenses'), ['category' => 'parking', 'amount' => 0]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame(['2026-09-29', '0.000'], [
            ApiClient::json($response)->get('entry', 'spent_on'),
            ApiClient::json($response)->get('entry', 'amount'),
        ]);
    }

    public function testALogShareWithoutCostsCanAddAnExpenseButNotAReminder(): void
    {
        $member = $this->createMember($this->app);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $driver = $this->api($this->app, $this->apiKey($this->app, $member));

        $response = $driver->post($this->path('expenses'), ['category' => 'parking', 'amount' => '4.50']);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame('4.500', ApiClient::json($response)->get('entry', 'amount'), 'their own entry carries its amount');

        $reminder = $driver->post($this->path('reminders'), ['title' => 'Wash it', 'due_on' => '2026-10-10']);
        self::assertSame(403, $reminder->getStatusCode(), 'manual reminders need Manage, as on the Reminders page');
    }

    public function testAManualReminderTakesTheOwnersLeadTimeAndARetryIsTheSameOne(): void
    {
        $body = ['title' => 'Book the MOT', 'due_on' => '2026-10-20'];
        $response = $this->api->post($this->path('reminders'), $body);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame(
            ['manual', 7, '2026-10-20'],
            [$entry->get('source'), $entry->get('lead_time_days'), $entry->get('due_on')],
        );

        $retry = $this->api->post(
            $this->path('reminders'),
            ['title' => 'book the MOT ', 'due_on' => '2026-10-20', 'lead_time_days' => 3],
        );
        self::assertSame(200, $retry->getStatusCode());
        self::assertSame($entry->int('id'), ApiClient::json($retry)->int('entry', 'id'));
    }

    public function testAManualReminderCanBeDueAtAnOdometerInTheRequestsUnit(): void
    {
        $response = $this->api->post($this->path('reminders'), [
            'title' => 'Front pads',
            'due_odometer' => '50000',
            'distance_unit' => 'mi',
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame([null, '80467.200'], [$entry->get('due_on'), $entry->get('due_odometer')]);

        $retry = $this->api->post(
            $this->path('reminders'),
            ['title' => 'Front pads', 'due_odometer' => '80467.2', 'distance_unit' => 'km'],
        );
        self::assertSame(200, $retry->getStatusCode(), 'the same odometer in km is the same reminder');

        $neither = $this->api->post($this->path('reminders'), ['title' => 'Something']);
        self::assertSame(422, $neither->getStatusCode());
        self::assertStringContainsString('due_on', self::body($neither));
    }

    public function testATreadCheckMeasuresTheFittedTyresInTheRequestsUnit(): void
    {
        $this->fitFronts();
        $response = $this->api->post($this->path('tyres/checks'), [
            'checked_on' => '2026-09-20',
            'odometer' => '30280',
            'depth_unit' => 'in32',
            'depths' => ['fl' => '8', 'fr' => 7.5],
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $check = ApiClient::json($response)->doc('entry');
        $depths = $check->get('depths');
        self::assertIsArray($depths);
        // 8/32″ × 0.79375 = 6.35 mm; 7.5/32″ = 5.953 mm.
        self::assertSame(['fl' => '6.350', 'fr' => '5.953'], array_column($depths, 'depth_mm', 'position'));

        $retry = $this->api->post($this->path('tyres/checks'), [
            'checked_on' => '2026-09-20',
            'odometer' => '30290',
            'depth_unit' => 'in32',
            'depths' => ['fr' => '7.5', 'fl' => '8.0'],
        ]);
        self::assertSame(200, $retry->getStatusCode(), 'the same date and depths');
        $other = $this->api->post(
            $this->path('tyres/checks'),
            ['checked_on' => '2026-09-20', 'odometer' => '30290', 'depths' => ['fl' => '6.3']],
        );
        self::assertSame(201, $other->getStatusCode(), 'another depth is another check');
    }

    public function testATreadCheckRefusesAPositionWithNoTyre(): void
    {
        $this->fitFronts();
        $response = $this->api->post($this->path('tyres/checks'), ['odometer' => '30280', 'depths' => ['rl' => '5']]);
        self::assertSame(422, $response->getStatusCode());
        $errors = ApiClient::json($response)->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('depths.rl', $errors);

        $response = $this->api->post($this->path('tyres/checks'), ['odometer' => '30280', 'depths' => ['fl' => '99']]);
        self::assertSame(422, $response->getStatusCode(), 'the form\'s limit');
        $errors = ApiClient::json($response)->get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('depths.fl', $errors);
    }

    public function testASwitchedOffModuleAnswers404AndArchivedVehiclesRefuse(): void
    {
        $this->service($this->app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Maintenance,
        )));
        $response = $this->api->post($this->path('maintenance'), ['category' => 'service', 'title' => 'Oil']);
        self::assertSame(404, $response->getStatusCode());

        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        $response = $this->api->post($this->path('expenses'), ['category' => 'parking', 'amount' => '1']);
        self::assertSame(409, $response->getStatusCode());
    }

    private function fitFronts(): void
    {
        $fitted = LocalTime::parseDate('2026-06-01');
        assert($fitted !== null);
        $this->service($this->app, TyreChangeService::class)->existing(
            $this->golf,
            new TyreChangeData($fitted, '40000.000'),
            [
                new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'), '7.000'),
                new NewTyre(TyrePosition::FrontRight, new TyreData('Michelin', 'Primacy 4'), '7.000'),
            ],
            new DateTimeZone('Europe/London'),
            'en_GB',
        );
    }
}
