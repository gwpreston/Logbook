<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * GET /api/v1/vehicles/{id}/true-cost and the summary's headline (spec.md
 * §7.20, §7.35): the pages' figures, checked against openapi.json by
 * ApiClient, and 403 without cost access.
 */
final class ApiTrueCostTest extends AppTestCase
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
        $this->pinClock($this->app, '2026-10-05T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->service($this->app, VehicleService::class)->create(
            $this->owner,
            new VehicleData(
                VehicleType::Car,
                'Volkswagen',
                'Golf',
                FuelType::Petrol,
                registration: 'GO19 ABC',
                purchaseDate: LocalTime::parseDate('2024-01-01'),
                purchasePrice: '15000.00',
            ),
        );
        $this->reading($this->app, $this->golf, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($this->app, $this->golf, '2024-12-31T09:00:00Z', '11000', '700', '1050.00');
        $this->fillUp($this->app, $this->golf, '2025-10-15T09:00:00Z', '19000', '520', '832.00');
        $this->fillUp($this->app, $this->golf, '2026-09-30T09:00:00Z', '26000', '455', '728.00');
        $this->document($this->app, $this->golf, ComplianceType::Insurance, '2025-03-01', '2026-02-28', '365.00');
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTheSameFiguresAsThePages(): void
    {
        $response = $this->api->get('/vehicles/' . $this->golf->id . '/true-cost');
        self::assertSame(200, $response->getStatusCode());
        $doc = ApiClient::json($response);

        $today = LocalTime::parseDate('2026-10-05');
        self::assertNotNull($today);
        $expected = $this->service($this->app, TrueCostService::class)->forVehicle($this->owner, $this->golf, $today);
        self::assertNotNull($expected?->lastTwelveMonths?->perKm);
        self::assertSame('last_12_months', $doc->get('period'));
        self::assertSame(Decimal::round($expected->lastTwelveMonths->perKm, 6), $doc->get('true_cost', 'per_distance'));
        self::assertSame(['2024', '2025', '2026 so far'], $doc->column('label', 'years'));
        self::assertSame(2024, $doc->get('years', 1, 'what_changed', 'against'));

        $since = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/true-cost?period=since_bought'));
        self::assertSame('since_bought', $since->get('period'));
        self::assertSame(Decimal::round((string) $expected->sinceBought->perKm, 6), $since->get('true_cost', 'per_distance'));

        $summary = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/summary'));
        self::assertSame(Decimal::round($expected->lastTwelveMonths->perKm, 4), $summary->get('costs', 'true_cost_per_distance'));
    }

    public function testWithoutCostAccessItIsForbidden(): void
    {
        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $viewer));

        self::assertSame(403, $theirs->get('/vehicles/' . $this->golf->id . '/true-cost')->getStatusCode());
        self::assertNull(ApiClient::json($theirs->get('/vehicles/' . $this->golf->id . '/summary'))->get('costs'));
    }
}
