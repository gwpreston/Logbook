<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Trip\TripService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Single-entry reads (Phase 39.1, spec.md §7.20 *Phase 39*): each list's
 * entry reads exactly as the list returns it (ApiClient checks the OpenAPI
 * shape), with an ETag of the stored entry; another vehicle's id, a trip
 * the key's user may not see and a switched-off module answer 404.
 */
final class ApiEntryReadTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string TAG = '/^"[0-9a-f]{32}"$/';

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
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

    private function garage(Vehicle $vehicle): void
    {
        $this->fillUp($this->app, $vehicle, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $this->fillUp($this->app, $vehicle, '2026-09-01T08:00:00Z', '40700', '44.5', '66.01');
        $this->reading($this->app, $vehicle, '41000', '2026-09-20T12:00:00Z');
        $this->maintenance($this->app, $vehicle, '2026-09-05', 'Annual service', '187.43', '40800');
        $this->document($this->app, $vehicle, ComplianceType::Inspection, '2025-10-15', '2026-10-14', '54.85', 'Test centre');
        $this->expense($this->app, $vehicle, '2026-09-05', '12.91');
        $this->service($this->app, TripService::class)->create($vehicle, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            '90.5',
        ));
        $incident = $this->api->post('/vehicles/' . $vehicle->id . '/incidents', [
            'occurred_on' => '2026-03-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'damage_areas' => ['rear'],
            'other_party_name' => 'A. Driver',
            'claim_number' => '4417',
            'payout' => '1000',
        ]);
        self::assertSame(201, $incident->getStatusCode(), (string) $incident->getBody());
    }

    public function testEachEntryReadsExactlyAsItsListReturnsIt(): void
    {
        $this->garage($this->golf);
        $base = '/vehicles/' . $this->golf->id;

        foreach (['fuel', 'odometer', 'maintenance', 'documents', 'expenses', 'trips', 'incidents'] as $list) {
            $page = ApiClient::json($this->api->get($base . '/' . $list));
            $items = $page->get('items');
            self::assertIsArray($items);
            self::assertNotSame([], $items, $list);
            foreach (array_keys($items) as $index) {
                $item = $items[$index];
                $response = $this->api->get($base . '/' . $list . '/' . $page->int('items', $index, 'id'));
                self::assertSame(200, $response->getStatusCode(), $list);
                self::assertSame($item, ApiClient::json($response)->toArray(), $list);
                self::assertMatchesRegularExpression(self::TAG, $response->getHeaderLine('ETag'), $list);
            }
        }
    }

    public function testAnEntryOfAnotherVehicleOrNoEntryIsNotFound(): void
    {
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $fill = $this->fillUp($this->app, $fiesta, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');

        $response = $this->api->get('/vehicles/' . $this->golf->id . '/fuel/' . $fill->id);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', ApiClient::json($response)->get('code'));
        self::assertSame(404, $this->api->get('/vehicles/' . $this->golf->id . '/maintenance/999999')->getStatusCode());
        self::assertSame(200, $this->api->get('/vehicles/' . $fiesta->id . '/fuel/' . $fill->id)->getStatusCode());
    }

    public function testTheTagFollowsTheStoredEntryNotItsDerivedFigures(): void
    {
        $first = $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $second = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40700', '44.5', '66.01');
        $path = '/vehicles/' . $this->golf->id . '/fuel/' . $second->id;
        $before = $this->api->get($path);
        self::assertNotNull(ApiClient::json($before)->get('economy', 'segment'), 'the second fill closes a segment');

        // Removing the first fill takes the second's economy away; the entry itself is unchanged.
        $fuel = $this->service($this->app, FuelService::class);
        $fuel->delete($this->golf, $first);
        $after = $this->api->get($path);
        self::assertNull(ApiClient::json($after)->get('economy', 'segment'));
        self::assertSame($before->getHeaderLine('ETag'), $after->getHeaderLine('ETag'));

        // A change to the stored entry changes the tag.
        $stored = $fuel->get($this->golf, $second->id);
        $fuel->update($this->golf, $stored, $stored->data->withStation(null, 'Corner garage'));
        self::assertNotSame($after->getHeaderLine('ETag'), $this->api->get($path)->getHeaderLine('ETag'));
    }

    public function testATripTheKeysUserMayNotSeeIsNotFound(): void
    {
        $this->garage($this->golf);
        $tripId = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/trips'))->int('items', 0, 'id');
        $driver = $this->createMember($this->app);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $driver->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $driver));

        self::assertSame(404, $theirs->get('/vehicles/' . $this->golf->id . '/trips/' . $tripId)->getStatusCode());
        self::assertSame(200, $this->api->get('/vehicles/' . $this->golf->id . '/trips/' . $tripId)->getStatusCode());
    }

    public function testAnIncidentReadWithoutDetailAccessLeavesTheDetailsOut(): void
    {
        $this->garage($this->golf);
        $id = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/incidents'))->int('items', 0, 'id');
        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));

        $seen = ApiClient::json($this->api($this->app, $this->apiKey($this->app, $viewer))
            ->get('/vehicles/' . $this->golf->id . '/incidents/' . $id));
        self::assertFalse($seen->get('details'));
        self::assertNull($seen->get('claim'));
        self::assertNull($seen->get('other_party'));
        self::assertNull($seen->get('costs'));
        self::assertStringNotContainsString('A. Driver', json_encode($seen->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testAnExpenseNeedsCostAccessAsTheListDoes(): void
    {
        $expense = $this->expense($this->app, $this->golf, '2026-09-05', '12.91');
        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));

        $response = $this->api($this->app, $this->apiKey($this->app, $viewer))
            ->get('/vehicles/' . $this->golf->id . '/expenses/' . $expense->id);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testWithTheModuleOffTheEntryIsNotFound(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $this->service($this->app, FeatureToggles::class)
            ->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Fuel)));

        self::assertSame(404, $this->api->get('/vehicles/' . $this->golf->id . '/fuel/' . $fill->id)->getStatusCode());
    }
}
