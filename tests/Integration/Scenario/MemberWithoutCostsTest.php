<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Tests\Support\ApiClient;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Scenario 5: a vehicle shared with members who can and cannot see costs
 * (spec.md §5, §7.21): without ViewCosts the cost of ownership page and API
 * route are 403 and the CSV leaves the vehicle out; with it the figures are
 * the owner's, to the penny.
 */
final class MemberWithoutCostsTest extends ScenarioTestCase
{
    public function testWithoutViewCostsNoSurfaceShowsAnAmount(): void
    {
        [$app, $owner, $ownerApi] = $this->start();
        $golf = $this->golf($app);
        $golden = self::golden('member-without-costs');
        $this->assertOwnershipAgrees($golden, $owner, $ownerApi, $golf);

        $member = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-08-01T00:00:00Z'));
        $browser = $this->browserFor($app, 'viewer');
        $api = $this->api($app, $this->apiKey($app, $member));
        $id = (string) $golf->id;

        self::assertSame(403, $browser->get('/vehicles/' . $id . '/ownership')->getStatusCode());
        $response = $api->get('/vehicles/' . $id . '/ownership?include=working');
        self::assertSame(403, $response->getStatusCode());
        self::assertNull(ApiClient::json($response)->get('working'));
        self::assertSame(403, $api->get('/vehicles/' . $id . '/true-cost')->getStatusCode());

        $csv = $browser->get('/reports/ownership.csv?vehicle=' . $id);
        self::assertSame(200, $csv->getStatusCode());
        self::assertCount(1, self::csvRows(self::body($csv)), 'the header only: the vehicle is left out');

        $seen = $response->getBody() . self::body($csv)
            . self::body($browser->get('/vehicles/' . $id . '/ownership'))
            . self::body($browser->get('/reports/ownership'))
            . self::body($browser->get('/vehicles/' . $id))
            . self::body($api->get('/vehicles/' . $id . '/summary'))
            . self::body($api->get('/vehicles/' . $id));
        foreach (self::strings($golden['hidden']) as $amount) {
            self::assertStringNotContainsString($amount, $seen, $amount . ' leaked');
        }
    }

    public function testAMemberWhoMaySeeCostsGetsTheOwnersFigures(): void
    {
        [$app] = $this->start();
        $golf = $this->golf($app);
        $member = $this->createMember($app, 'partner');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-08-01T00:00:00Z'));

        $this->assertOwnershipAgrees(
            self::golden('member-without-costs'),
            $this->browserFor($app, 'partner'),
            $this->api($app, $this->apiKey($app, $member)),
            $golf,
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app): Vehicle
    {
        $golf = $this->bought($app, '2025-03-01', '14250.37');
        $this->reading($app, $golf, '16093.44', '2025-03-01T12:00:00Z');
        $this->fillUp($app, $golf, '2025-06-01T08:00:00Z', '17702.784', '40', '71.37');
        $this->reading($app, $golf, '20921.472', '2026-03-01T12:00:00Z');
        $this->service($app, ValuationService::class)
            ->create($golf, new VehicleValuationData(self::date('2026-03-01'), '9876.54'));
        $this->reading($app, $golf, '24140.16', '2026-09-01T09:00:00Z');

        return $golf;
    }
}
