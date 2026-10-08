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
use Logbook\Service\Maintenance\MaintenanceService;
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

    public function testAValuationIsAddedEditedAndDeletedAsThePagesDo(): void
    {
        $path = '/vehicles/' . $this->golf->id . '/valuations';

        $created = $this->api->post($path, ['amount' => 12500, 'source' => 'Dealer quote']);
        self::assertSame(201, $created->getStatusCode(), self::body($created));
        $entry = ApiClient::json($created)->doc('entry');
        self::assertSame('2026-09-30', $entry->get('valued_on'), 'today in the owner\'s zone');
        self::assertSame('12500.000', $entry->get('amount'));
        $id = $entry->int('id');
        $again = $this->api->post($path, ['amount' => '12500.0', 'source' => 'dealer quote', 'valued_on' => '2026-09-30']);
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue(ApiClient::json($again)->get('duplicate'));
        self::assertSame($id, ApiClient::json($again)->int('entry', 'id'));

        foreach ([['valued_on' => '2026-10-01'], ['valued_on' => '2024-09-01'], ['amount' => '-1']] as $bad) {
            self::assertSame(422, $this->api->post($path, $bad + ['amount' => '1'])->getStatusCode(), (string) json_encode($bad));
        }

        $tag = $this->api->get($path . '/' . $id)->getHeaderLine('ETag');
        $edited = $this->api->patch($path . '/' . $id, ['amount' => '12000', 'notes' => 'After the MOT'], ['If-Match' => $tag]);
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        self::assertSame('12000.000', ApiClient::json($edited)->get('entry', 'amount'));
        self::assertSame('Dealer quote', ApiClient::json($edited)->get('entry', 'source'), 'unsent fields stay');
        self::assertSame(412, $this->api->patch($path . '/' . $id, ['amount' => '1'], ['If-Match' => $tag])->getStatusCode());

        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $logger));
        self::assertSame(403, $theirs->post($path, ['amount' => '1'])->getStatusCode(), 'Manage, as the page');
        self::assertSame(403, $theirs->delete($path . '/' . $id)->getStatusCode());

        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        $scrap = $this->api->post($path, ['amount' => '350', 'source' => 'Scrap yard']);
        self::assertSame(201, $scrap->getStatusCode(), 'the one write an archived vehicle takes');
        self::assertSame(204, $this->api->delete($path . '/' . $id)->getStatusCode());
        self::assertSame(404, $this->api->get($path . '/' . $id)->getStatusCode());
    }

    public function testAScheduleIsAddedEditedAndDeletedAsThePagesDo(): void
    {
        $path = '/vehicles/' . $this->golf->id . '/schedules';
        $body = ['category' => 'oil', 'title' => 'Oil change', 'interval_distance' => 6000, 'interval_months' => 12];

        $created = $this->api->post($path, $body);
        self::assertSame(201, $created->getStatusCode(), self::body($created));
        $entry = ApiClient::json($created)->doc('entry');
        self::assertSame('9656.064', $entry->get('interval_km'), '6,000 mi, the owner\'s unit');
        $id = $entry->int('id');
        $again = $this->api->post($path, ['interval_km' => '9656.064'] + array_diff_key($body, ['interval_distance' => 1]));
        self::assertSame(200, $again->getStatusCode(), 'the same intervals');
        self::assertTrue(ApiClient::json($again)->get('duplicate'));

        $both = $this->api->post($path, ['interval_km' => '10000'] + $body);
        $key = ApiClient::json($both)->get('errors', 'interval_distance', 'key');
        self::assertSame('api.validation.interval_km_or_distance', $key);
        $none = $this->api->post($path, ['category' => 'oil', 'title' => 'No interval']);
        self::assertSame('maintenance.schedule.need_interval', ApiClient::json($none)->get('errors', 'interval_km', 'key'));

        $edited = ApiClient::json($this->api->patch($path . '/' . $id, ['title' => 'Oil and filter']));
        self::assertSame('Oil and filter', $edited->get('entry', 'title'));
        self::assertSame('9656.064', $edited->get('entry', 'interval_km'), 'exactly as stored');
        $months = ApiClient::json($this->api->patch($path . '/' . $id, ['interval_distance' => null]));
        self::assertNull($months->get('entry', 'interval_km'));
        $none = $this->api->patch($path . '/' . $id, ['interval_months' => null]);
        self::assertSame(422, $none->getStatusCode(), 'one interval at least');

        $this->maintenance($this->app, $this->golf, '2026-09-01', 'Oil change', '60', '20000');
        $record = $this->service($this->app, MaintenanceService::class)->history($this->golf)->entries[0];
        self::assertSame(204, $this->api->delete($path . '/' . $id)->getStatusCode());
        $kept = $this->api->get('/vehicles/' . $this->golf->id . '/maintenance/' . $record->id);
        self::assertSame(200, $kept->getStatusCode(), 'records stay');

        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        self::assertSame('vehicle_archived', ApiClient::json($this->api->post($path, $body))->get('code'));
    }
}
