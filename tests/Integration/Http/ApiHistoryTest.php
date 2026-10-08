<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Trip\TripService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The history feeds over the API (Phase 39.1, spec.md §7.16, §7.20): one
 * vehicle's and the fleet's, newest first, `kinds`, `since`, `until`, a
 * cursor that never repeats or skips an item, links to the single reads,
 * and amounts only for those who may see them.
 */
final class ApiHistoryTest extends AppTestCase
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

    public function testTheFeedIsNewestFirstFilteredAndLinked(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $service = $this->maintenance($this->app, $this->golf, '2026-09-05', 'Annual service', '187.43', '40800');
        $expense = $this->expense($this->app, $this->golf, '2026-09-06', '12.91');
        $path = '/vehicles/' . $this->golf->id . '/history';

        $feed = ApiClient::json($this->api->get($path));
        self::assertSame(['expense', 'maintenance', 'fuel'], $feed->column('kind', 'items'));
        self::assertSame([$expense->id, $service->id, $fill->id], $feed->column('entry_id', 'items'));
        self::assertSame('2026-09-05', $feed->get('items', 1, 'date'));
        self::assertSame('Annual service', $feed->get('items', 1, 'summary'));
        self::assertSame('187.430', $feed->get('items', 1, 'amount'));
        $link = $feed->string('items', 2, 'links', 'entry');
        self::assertSame('/vehicles/' . $this->golf->id . '/fuel/' . $fill->id, $link);
        self::assertSame(200, $this->api->get($link)->getStatusCode(), 'the link is a single read');

        self::assertSame(['fuel'], ApiClient::json($this->api->get($path . '?kinds=fuel,odometer'))->column('kind', 'items'));
        self::assertSame(['maintenance'], ApiClient::json($this->api->get($path . '?until=2026-09-05&since=2026-09-01'))
            ->column('kind', 'items'), 'until is inclusive of its day only');
        self::assertSame(
            ['expense', 'maintenance'],
            ApiClient::json($this->api->get($path . '?since=2026-09-05&until=2026-09-06'))->column('kind', 'items'),
        );
        self::assertSame(400, $this->api->get($path . '?kinds=fuel,picnic')->getStatusCode());
        self::assertSame(400, $this->api->get($path . '?since=yesterday')->getStatusCode());
    }

    public function testTheCursorWalksTheWholeFeedOnce(): void
    {
        // Same day, so the order rests on when each was added and its kind.
        foreach (['10', '11', '12'] as $i => $amount) {
            $this->expense($this->app, $this->golf, '2026-09-05', $amount);
            $this->maintenance($this->app, $this->golf, '2026-09-05', 'Check ' . $i, $amount);
        }
        $all = ApiClient::json($this->api->get('/history'))->column('entry_id', 'items');
        self::assertCount(6, $all);

        $seen = [];
        $next = '/history?limit=4';
        while ($next !== null) {
            $page = ApiClient::json($this->api->get($next));
            foreach (array_keys($page->doc('items')->toArray()) as $i) {
                $seen[] = $page->string('items', $i, 'kind') . ':' . $page->int('items', $i, 'entry_id');
            }
            $url = $page->get('next');
            $next = is_string($url) ? substr($url, (int) strpos($url, '/history')) : null;
        }
        self::assertCount(6, array_unique($seen));
        self::assertSame(400, $this->api->get('/history?cursor=nonsense')->getStatusCode());
    }

    public function testAnotherDriversTripStaysOutOfTheFeed(): void
    {
        $this->service($this->app, TripService::class)->create($this->golf, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            '90.5',
        ));
        self::assertSame(['trip'], ApiClient::json($this->api->get('/history'))->column('kind', 'items'));

        $driver = $this->createMember($this->app, 'driver');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $driver->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $driver));
        self::assertSame([], ApiClient::json($theirs->get('/history'))->get('items'), 'the owner\'s trip is not theirs to see');
        self::assertSame([], ApiClient::json($theirs->get('/vehicles/' . $this->golf->id . '/history'))->get('items'));
    }

    public function testTheFleetFeedCoversTheVisibleVehiclesAndHidesAmountsWithoutCosts(): void
    {
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $this->expense($this->app, $this->golf, '2026-09-05', '12.91');
        $this->expense($this->app, $fiesta, '2026-09-06', '9.50');

        self::assertSame(
            [$fiesta->id, $this->golf->id],
            ApiClient::json($this->api->get('/history'))->column('vehicle_id', 'items'),
        );
        self::assertSame(
            [$this->golf->id],
            ApiClient::json($this->api->get('/history?vehicle=' . $this->golf->id))->column('vehicle_id', 'items'),
        );

        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = ApiClient::json($this->api($this->app, $this->apiKey($this->app, $viewer))->get('/history'));
        self::assertSame([$this->golf->id], $theirs->column('vehicle_id', 'items'), 'only what they can see');
        self::assertNull($theirs->get('items', 0, 'amount'));
        self::assertNull($theirs->get('items', 0, 'currency'));
    }
}
