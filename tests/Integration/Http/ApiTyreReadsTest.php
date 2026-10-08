<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\SetChoice;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Tyre changes and sets over the API (Phase 39.1, spec.md §7.17, §7.20):
 * changes newest first with their lines, sets with their tyres, per vehicle
 * and for the fleet; gone with the tyres module off.
 */
final class ApiTyreReadsTest extends AppTestCase
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
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
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

    public function testChangesAndSetsReadAsTheTyresTab(): void
    {
        $changes = $this->service($this->app, TyreChangeService::class);
        $zone = new DateTimeZone('Europe/London');
        $data = new TyreData('Goodyear', 'EfficientGrip', '205/55 R16 91V');
        $existing = $changes->existing(
            $this->golf,
            new TyreChangeData(self::day('2025-10-03'), '20000.000'),
            array_map(
                static fn (P $p): NewTyre => new NewTyre($p, $data),
                [P::FrontLeft, P::FrontRight, P::RearLeft, P::RearRight],
            ),
            $zone,
            'en_GB',
        );
        $swap = $changes->swap(
            $this->golf,
            new TyreChangeData(self::day('2025-11-05'), '21500.000', note: 'Winter on'),
            new SetChoice(newSet: new TyreSetData('Summer wheels', 'Garage loft')),
            [],
            null,
            $zone,
            'en_GB',
        );
        $base = '/vehicles/' . $this->golf->id;

        $list = ApiClient::json($this->api->get($base . '/tyres/changes'));
        self::assertSame([$swap->id, $existing->id], $list->column('id', 'items'));
        self::assertSame('swap', $list->get('items', 0, 'kind'));
        self::assertSame('2025-11-05', $list->get('items', 0, 'changed_on'));
        self::assertSame('Winter on', $list->get('items', 0, 'note'));
        self::assertSame(['off', 'off', 'off', 'off'], $list->column('action', 'items', 0, 'lines'));
        self::assertSame(['on', 'on', 'on', 'on'], $list->column('action', 'items', 1, 'lines'));

        $sets = ApiClient::json($this->api->get($base . '/tyre-sets'));
        self::assertSame(['Summer wheels'], $sets->column('name', 'items'));
        self::assertSame('Garage loft', $sets->get('items', 0, 'storage_location'));
        self::assertSame(['stored', 'stored', 'stored', 'stored'], $sets->column('status', 'items', 0, 'tyres'));
        self::assertSame($sets->toArray(), ApiClient::json($this->api->get('/tyre-sets'))->toArray());
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        self::assertSame([], ApiClient::json($this->api->get('/tyre-sets?vehicle=' . $fiesta->id))->get('items'));

        $this->service($this->app, FeatureToggles::class)
            ->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Tyres)));
        self::assertSame(404, $this->api->get($base . '/tyres/changes')->getStatusCode());
        self::assertSame(404, $this->api->get('/tyre-sets')->getStatusCode());
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
