<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;

/**
 * The reports over the API (Phase 39.1, spec.md §7.7, §7.20): on the
 * sample data, every report's totals equal the Reports page's service for
 * every demo vehicle, the fleet and every range; vehicles without visible
 * costs are left out and listed in `excluded`; strict parameters.
 */
final class ApiReportsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testEveryReportMatchesTheReportsPageOnTheSampleData(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, '2026-10-05T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $api = $this->api($app, $this->apiKey($app, $demo));
        $today = LocalTime::parseDate('2026-10-05');
        self::assertNotNull($today);
        $vehicles = $this->service($app, VehicleService::class)->listFleet($demo, true);
        self::assertNotEmpty($vehicles);
        $reports = $this->service($app, ReportService::class);

        $scopes = [null, ...array_map(static fn ($v): int => $v->id, $vehicles)];
        $ranges = [ReportRange::ThisMonth, ReportRange::TwelveMonths, ReportRange::ThisYear, ReportRange::AllTime];
        foreach ($ranges as $range) {
            foreach ($scopes as $vehicleId) {
                $params = ['range' => $range->value, 'include_archived' => '1']
                    + ($vehicleId === null ? [] : ['vehicle' => (string) $vehicleId]);
                $query = '?' . http_build_query($params);
                $label = $range->value . ' ' . ($vehicleId ?? 'fleet');
                $page = $reports->build($demo, ReportFilter::fromQuery($params, $today));
                $expected = $page->isEmpty() ? [] : array_map(static fn (CurrencyReport $c): array => [
                    'currency' => $c->currency,
                    'total' => $c->total->toDecimal(Serializer::QUANTITY_SCALE),
                    'entries' => $c->count,
                    'distance_km' => Serializer::dec($c->distanceKm, Serializer::QUANTITY_SCALE),
                ], $page->currencies);

                foreach (['costs', 'cost-per-distance'] as $report) {
                    $doc = ApiClient::json($api->get('/reports/' . $report . $query));
                    $got = array_map(static fn (int $i): array => [
                        'currency' => $doc->get('currencies', $i, 'currency'),
                        'total' => $doc->get('currencies', $i, 'total'),
                        'entries' => $doc->get('currencies', $i, 'entries'),
                        'distance_km' => $doc->get('currencies', $i, 'distance_km'),
                    ], array_keys($doc->doc('currencies')->toArray()));
                    self::assertSame($expected, $got, $report . ' ' . $label);
                    self::assertSame(Serializer::date($page->period->from), $doc->get('period', 'from'), $label);
                }

                $mileage = ApiClient::json($api->get('/reports/mileage' . $query));
                foreach ($page->vehicles as $index => $vehicle) {
                    $km = PeriodDistance::km(
                        $this->service($app, OdometerService::class)->history($vehicle)->readings,
                        $page->filter->period,
                        $demo->preferences->timeZone(),
                    );
                    $distance = $mileage->get('by_vehicle', $index, 'distance_km');
                    self::assertSame(Serializer::dec($km, Serializer::QUANTITY_SCALE), $distance, $label);
                }
                self::assertSame(200, $api->get('/reports/fuel' . $query)->getStatusCode(), $label);
            }
        }
    }

    public function testThePeriodIsTheKeyUsersCalendar(): void
    {
        $app = $this->createApp();
        // 30 Sep 11:30 UTC is already 1 October in Auckland.
        $this->pinClock($app, '2026-09-30T11:30:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, preferences: DisplayPreferences::defaults('en_GB', 'Pacific/Auckland', 'NZD'));
        $golf = $this->vehicle($app);
        $this->expense($app, $golf, '2026-09-30', '5.00');
        $this->expense($app, $golf, '2026-10-01', '7.50');
        $api = $this->api($app, $this->apiKey($app, $owner));

        $month = ApiClient::json($api->get('/reports/costs?range=month'));
        self::assertSame('2026-10-01', $month->get('period', 'from'));
        self::assertSame('2026-10-01', $month->get('period', 'to'));
        self::assertSame('7.500', $month->get('currencies', 0, 'total'), 'only 1 October counts');
    }

    public function testVehiclesWithoutVisibleCostsAreExcludedNotCounted(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->expense($app, $golf, '2026-09-05', '12.91');
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-20T08:00:00Z', '10600', '42', '63.00');

        $member = $this->createMember($app, 'partner');
        $theirs = $this->service($app, VehicleService::class)->create($member, new VehicleData(
            VehicleType::Car,
            'Ford',
            'Fiesta',
            FuelType::Petrol,
        ));
        $this->expense($app, $theirs, '2026-09-06', '5.00');
        $this->service($app, VehicleShareRepository::class)
            ->insert($theirs->id, $owner->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $api = $this->api($app, $this->apiKey($app, $owner));

        $costs = ApiClient::json($api->get('/reports/costs?group_by=vehicle'));
        self::assertSame([$golf->id], $costs->get('vehicles'));
        self::assertSame([$theirs->id], $costs->get('excluded'));
        self::assertSame('135.910', $costs->get('currencies', 0, 'total'));
        self::assertSame([$golf->id], $costs->column('vehicle_id', 'currencies', 0, 'by_vehicle'));

        $fuel = ApiClient::json($api->get('/reports/fuel?vehicle=' . $golf->id));
        self::assertSame('82.000', $fuel->get('by_vehicle', 0, 'kinds', 0, 'volume'));
        self::assertSame('123.000', $fuel->get('by_vehicle', 0, 'kinds', 0, 'spend'));

        $partnerKey = $this->api($app, $this->apiKey($app, $member));
        $shared = $this->service($app, VehicleShareRepository::class);
        $shared->insert($golf->id, $member->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $seen = ApiClient::json($partnerKey->get('/reports/fuel?vehicle=' . $golf->id));
        self::assertFalse($seen->has('by_vehicle', 0, 'kinds', 0, 'spend'), 'spend left out without costs');
        self::assertNull($seen->get('by_vehicle', 0, 'currency'));

        foreach (['range=fortnight', 'group_by=week', 'group=snacks', 'range=custom&from=2026-02-30', 'vehicle=x'] as $bad) {
            self::assertSame(400, $api->get('/reports/costs?' . $bad)->getStatusCode(), $bad);
        }
        self::assertSame(404, $api->get('/reports/mileage?vehicle=999999')->getStatusCode());
    }
}
