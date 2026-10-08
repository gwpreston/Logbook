<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attention\AttentionHiding;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Needs attention* over the API (Phase 39.1, spec.md §7.24, §7.20): the
 * page's items in its order and words, a `key` for a hideable item, a link
 * to the fix, hidden items left out.
 */
final class ApiAttentionTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTheItemsAreThePagesWithKeysAndLinks(): void
    {
        $this->service($this->app, ValuationService::class)->create($this->golf, new VehicleValuationData(
            new DateTimeImmutable('2024-06-01', new DateTimeZone('UTC')),
            '12000',
        ));
        $reminder = ApiClient::json($this->api->post('/vehicles/' . $this->golf->id . '/reminders', [
            'title' => 'Wash the car',
            'due_on' => '2026-09-01',
        ]))->int('entry', 'id');

        $list = ApiClient::json($this->api->get('/attention'));
        self::assertSame(['overdue', 'valuation_stale'], $list->column('kind', 'items'));
        self::assertSame('now', $list->get('items', 0, 'severity'));
        self::assertSame($reminder, $list->get('items', 0, 'reminder_id'));
        self::assertNull($list->get('items', 0, 'key'), 'Now items are dismissed through their reminder');
        self::assertSame('/vehicles/' . $this->golf->id . '/valuations', $list->get('items', 1, 'links', 'fix'));
        $key = $list->string('items', 1, 'key');
        self::assertStringStartsWith($this->golf->id . '.valuation_stale.' . $this->golf->id . '.', $key);
        self::assertNotSame('', $list->string('items', 1, 'title'));

        self::assertSame(
            $list->toArray(),
            ApiClient::json($this->api->get('/attention?vehicle=' . $this->golf->id))->toArray(),
        );
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        self::assertSame([], ApiClient::json($this->api->get('/attention?vehicle=' . $fiesta->id))->get('items'));

        [, , $subject, $fingerprint] = explode('.', $key);
        $owner = $this->owner($this->app);
        self::assertTrue($this->service($this->app, AttentionHiding::class)
            ->hide($owner, $this->golf, 'valuation_stale', (int) $subject, $fingerprint));
        self::assertSame(['overdue'], ApiClient::json($this->api->get('/attention'))->column('kind', 'items'), 'hidden: left out');
    }
}
