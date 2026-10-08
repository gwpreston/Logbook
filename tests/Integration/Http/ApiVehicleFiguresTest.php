<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Schedules, valuations and ownership over the API (Phase 39.1, spec.md
 * §7.20 *Phase 39*): schedules with the Maintenance tab's due state,
 * valuations newest first and paged, ownership with depreciation; costs
 * only with ViewCosts, schedules gone with Maintenance off.
 */
final class ApiVehicleFiguresTest extends AppTestCase
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
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->service($this->app, VehicleService::class)->create($this->owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'GO19 ABC',
            purchaseDate: new DateTimeImmutable('2024-09-30', new DateTimeZone('UTC')),
            purchasePrice: '15000',
        ));
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    private function valuation(string $on, string $amount, ?string $source = null): int
    {
        return $this->service($this->app, ValuationService::class)->create($this->golf, new VehicleValuationData(
            new DateTimeImmutable($on, new DateTimeZone('UTC')),
            $amount,
            $source,
        ))->id;
    }

    public function testSchedulesCarryTheirDueStateMostUrgentFirst(): void
    {
        $schedules = $this->service($this->app, ScheduleService::class);
        $oil = $schedules->create($this->golf, new MaintenanceScheduleData(
            MaintenanceCategory::Oil,
            'Oil change',
            intervalMonths: 12,
            baselineDoneOn: new DateTimeImmutable('2025-09-01', new DateTimeZone('UTC')),
        ));
        $service = $schedules->create($this->golf, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            intervalMonths: 24,
            baselineDoneOn: new DateTimeImmutable('2025-06-01', new DateTimeZone('UTC')),
        ));
        $path = '/vehicles/' . $this->golf->id . '/schedules';

        $list = ApiClient::json($this->api->get($path));
        self::assertSame([$oil->id, $service->id], $list->column('id', 'items'));
        $first = $list->doc('items', 0);
        self::assertSame('overdue', $first->get('status'));
        self::assertSame('2026-09-01', $first->get('next_due_on'));
        self::assertSame('date', $first->get('trigger'));
        self::assertSame(-29, $first->get('days_left'));
        self::assertFalse($first->get('due_on_projected'));
        self::assertSame('ok', $list->get('items', 1, 'status'));

        $one = $this->api->get($path . '/' . $service->id);
        self::assertSame($list->doc('items', 1)->toArray(), ApiClient::json($one)->toArray());
        self::assertNotSame('', $one->getHeaderLine('ETag'));
        self::assertSame(404, $this->api->get($path . '/999999')->getStatusCode());

        $this->service($this->app, FeatureToggles::class)
            ->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Maintenance)));
        self::assertSame(404, $this->api->get($path)->getStatusCode());
        self::assertSame(404, $this->api->get($path . '/' . $oil->id)->getStatusCode());
    }

    public function testValuationsAreNewestFirstAndPaged(): void
    {
        $older = $this->valuation('2025-09-30', '13000', 'Trade-in quote');
        $newer = $this->valuation('2026-09-01', '11500.5');
        $path = '/vehicles/' . $this->golf->id . '/valuations';

        $page = ApiClient::json($this->api->get($path . '?limit=1'));
        self::assertSame([$newer], $page->column('id', 'items'));
        self::assertSame('11500.500', $page->get('items', 0, 'amount'));
        self::assertSame('GBP', $page->get('items', 0, 'currency'));
        $next = $page->get('next');
        self::assertIsString($next);
        self::assertSame([$older], ApiClient::json($this->api->get(substr($next, (int) strpos($next, '/vehicles'))))
            ->column('id', 'items'));

        $one = ApiClient::json($this->api->get($path . '/' . $older));
        self::assertSame('Trade-in quote', $one->get('source'));
        self::assertSame('2025-09-30', $one->get('valued_on'));
    }

    public function testOwnershipCarriesDepreciationAndItsState(): void
    {
        $path = '/vehicles/' . $this->golf->id . '/ownership';
        $before = ApiClient::json($this->api->get($path));
        self::assertSame('no_value', $before->get('depreciation', 'state'));
        self::assertNull($before->get('depreciation', 'change'));
        self::assertSame('15000.000', $before->get('purchase_price'));

        $this->valuation('2026-09-01', '12000');
        $this->fillUp($this->app, $this->golf, '2026-09-10T08:00:00Z', '30000', '40', '60.00');
        $after = ApiClient::json($this->api->get($path));
        self::assertSame('ready', $after->get('depreciation', 'state'));
        self::assertSame('-3000.000', $after->get('depreciation', 'change'));
        self::assertSame('-0.200000', $after->get('depreciation', 'fraction'));
        self::assertSame('12000.000', $after->get('current_value', 'amount'));
        self::assertSame('valuation', $after->get('current_value', 'kind'));
        self::assertSame('60.000', $after->get('running_costs'));
        self::assertIsString($after->get('display', 'current_value'));
        self::assertStringContainsString('12,000', $after->get('display', 'current_value'));
    }

    public function testCostsNeedViewCosts(): void
    {
        $valuation = $this->valuation('2026-09-01', '12000');
        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $viewer));
        $base = '/vehicles/' . $this->golf->id;

        self::assertSame(403, $theirs->get($base . '/valuations')->getStatusCode());
        self::assertSame(403, $theirs->get($base . '/valuations/' . $valuation)->getStatusCode());
        self::assertSame(403, $theirs->get($base . '/ownership')->getStatusCode());
        self::assertSame(200, $theirs->get($base . '/schedules')->getStatusCode(), 'schedules have no amounts');
    }
}
