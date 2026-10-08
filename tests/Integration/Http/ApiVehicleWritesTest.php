<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Vehicles over the API (Phase 39.2, spec.md §7.20, #284): create through
 * the add form with a ten-minute duplicate key, edit through the edit form
 * (`Manage`), archive through the *Archive* page and restore (`Own`).
 */
final class ApiVehicleWritesTest extends AppTestCase
{
    use ApiFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private MutableClock $clock;
    private User $owner;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->clock = $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(array $extra = []): int
    {
        $response = $this->api->post('/vehicles', $extra + [
            'type' => 'car',
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'fuel_type' => 'petrol',
            'registration' => 'GO19 ABC',
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response)->int('entry', 'id');
    }

    public function testAVehicleIsCreatedAsTheAddFormCreatesIt(): void
    {
        $response = $this->api->post('/vehicles', [
            'type' => 'car',
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'fuel_type' => 'petrol',
            'registration' => 'go19  abc',
            'first_registered_on' => '2024-03-01',
            'current_odometer' => 12000,
            'distance_unit' => 'km',
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $created = ApiClient::json($response);
        self::assertFalse($created->get('duplicate'));
        $id = $created->int('entry', 'id');
        self::assertSame($created->doc('entry')->toArray(), ApiClient::json($this->api->get('/vehicles/' . $id))->toArray());
        self::assertSame('GO19 ABC', $created->get('entry', 'registration'), 'as the form stores it');
        self::assertSame('2027-03-01', $created->get('entry', 'first_inspection_due_on'), 'the form\'s suggestion');
        $readings = ApiClient::json($this->api->get('/vehicles/' . $id . '/odometer'));
        self::assertSame('12000.000', $readings->get('items', 0, 'odometer'));
        self::assertSame([$id], ApiClient::json($this->api->get('/vehicles'))->column('id', 'items'), 'the key\'s user owns it');

        $cleared = ApiClient::json($this->api->post('/vehicles', [
            'type' => 'bike', 'make' => 'Honda', 'model' => 'CB500', 'fuel_type' => 'petrol',
            'first_registered_on' => '2024-03-01', 'first_inspection_due_on' => null,
        ]));
        self::assertNull($cleared->get('entry', 'first_inspection_due_on'), 'null: none, not the suggestion');

        $invalid = $this->api->post('/vehicles', ['make' => 'Ford', 'colour' => 'red']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['colour'], array_keys(ApiClient::json($invalid)->doc('errors')->toArray()));
        $missing = ApiClient::json($this->api->post('/vehicles', ['make' => 'Ford']));
        self::assertSame(['type', 'model', 'fuel_type'], array_keys($missing->doc('errors')->toArray()));
        $reader = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        self::assertSame('insufficient_scope', ApiClient::json($reader->post('/vehicles', ['make' => 'Ford']))->get('code'));
    }

    public function testARetryWithinTenMinutesIsTheSameVehicle(): void
    {
        $id = $this->create();
        $body = [
            'type' => 'car', 'make' => 'volkswagen', 'model' => 'GOLF', 'fuel_type' => 'petrol', 'registration' => 'go19 abc',
        ];

        $this->clock->set(new DateTimeImmutable('2026-09-30T12:09:00Z'));
        $again = $this->api->post('/vehicles', $body);
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue(ApiClient::json($again)->get('duplicate'));
        self::assertSame($id, ApiClient::json($again)->int('entry', 'id'));
        self::assertSame(201, $this->api->post('/vehicles', ['registration' => 'OTHER 1'] + $body)->getStatusCode());

        $this->clock->set(new DateTimeImmutable('2026-09-30T12:11:00Z'));
        self::assertSame(201, $this->api->post('/vehicles', $body)->getStatusCode(), 'ten minutes on: a second one');
    }

    public function testAVehicleIsEditedAsTheEditFormEditsIt(): void
    {
        $id = $this->create([
            'capacity' => '50', 'volume_unit' => 'l', 'purchase_date' => '2025-03-01', 'purchase_price' => '14000',
        ]);
        $path = '/vehicles/' . $id;
        $tag = $this->api->get($path)->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $tag);

        $sold = $this->api->patch($path, ['sale_date' => '2026-09-20', 'sale_price' => '9000'], ['If-Match' => $tag]);
        self::assertSame(200, $sold->getStatusCode(), self::body($sold));
        self::assertSame('sold', ApiClient::json($sold)->get('entry', 'disposal'), 'a sale date marks it sold');
        self::assertSame('50.000', ApiClient::json($sold)->get('entry', 'capacity'), 'unsent fields stay exactly');
        self::assertSame($sold->getHeaderLine('ETag'), $this->api->get($path)->getHeaderLine('ETag'));
        self::assertSame(412, $this->api->patch($path, ['nickname' => 'x'], ['If-Match' => $tag])->getStatusCode());

        $active = ApiClient::json($this->api->patch($path, ['sale_date' => null, 'sale_price' => null]));
        self::assertNull($active->get('entry', 'disposal'), 'clearing it clears that');
        $before = $this->api->patch($path, ['sale_date' => '2025-01-01', 'sale_price' => '1']);
        self::assertSame('vehicle.sale_before_purchase', ApiClient::json($before)->get('errors', 'sale_date', 'key'));
        self::assertSame(422, $this->api->patch($path, ['make' => null])->getStatusCode());

        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $logger));
        self::assertSame(403, $theirs->patch($path, ['nickname' => 'Mine'])->getStatusCode(), 'Manage, as the page');
    }

    public function testArchiveIsThePagesAndRestoreBringsItBack(): void
    {
        $id = $this->create();
        $path = '/vehicles/' . $id;

        $manager = $this->createMember($this->app, 'manager');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($id, $manager->id, ShareLevel::Manage, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $manager));
        self::assertSame(403, $theirs->post($path . '/archive', [])->getStatusCode(), 'Own, as the page');

        $notOffered = $this->api->post($path . '/archive', ['disposal' => 'written_off']);
        self::assertSame(422, $notOffered->getStatusCode(), 'no settled write-off to archive as');
        self::assertSame('validation.choice', ApiClient::json($notOffered)->get('errors', 'disposal', 'key'));

        $archived = $this->api->post($path . '/archive', []);
        self::assertSame(200, $archived->getStatusCode(), self::body($archived));
        self::assertSame('archived', ApiClient::json($archived)->get('entry', 'status'));
        self::assertSame('vehicle_archived', ApiClient::json($this->api->post($path . '/archive', []))->get('code'));
        self::assertSame('vehicle_archived', ApiClient::json($this->api->patch($path, ['nickname' => 'x']))->get('code'));

        $restored = $this->api->post($path . '/restore', []);
        self::assertSame(200, $restored->getStatusCode());
        self::assertSame('active', ApiClient::json($restored)->get('entry', 'status'));
        self::assertSame(200, $this->api->post($path . '/restore', [])->getStatusCode(), 'active: left as it is');
    }

    public function testACreateLeavesTheVehicleAsTheAddPageDoes(): void
    {
        $form = [
            'type' => 'car', 'make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'Estate', 'fuel_type' => 'diesel',
            'registration' => 'OC21 TAV', 'year' => '2021', 'first_registered_on' => '2021-06-01',
            'purchase_date' => '2023-02-01', 'purchase_price' => '15995', 'purchase_odometer' => '30000',
        ];
        $browser = new TestBrowser($this->app);
        $browser->get('/login');
        $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame(303, $browser->post('/vehicles/new', $form + ['current_odometer_on' => '2026-09-30'])->getStatusCode());
        $api = $this->api->post('/vehicles', ['registration' => 'OC21 TAW'] + $form);
        self::assertSame(201, $api->getStatusCode(), self::body($api));

        $rows = $this->connection($this->app)->createQueryBuilder()->select('*')->from('vehicles')->orderBy('id')
            ->fetchAllAssociative();
        self::assertCount(2, $rows);
        foreach (['id', 'registration', 'created_at', 'updated_at'] as $column) {
            unset($rows[0][$column], $rows[1][$column]);
        }
        self::assertSame($rows[0], $rows[1], 'the same vehicle, whichever way it came in');
    }
}
