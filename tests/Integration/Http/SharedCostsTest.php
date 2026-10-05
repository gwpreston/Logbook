<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * A Log share without costs (spec.md §7.21): the driver adds a fill-up and
 * sees its amount, and never any other amount, report figure or valuation
 * of the vehicle, in the pages, print, CSV or the API. Sentinel amounts
 * make any leak visible.
 */
final class SharedCostsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** The owner's amounts: purchase, fill-up, service, insurance, parking, a valuation. */
    private const array OWNERS = ['14,250.37', '71.37', '187.43', '243.19', '12.91', '9,876.54'];
    private const string MINE = '61.23';

    public function testTheDriverSeesTheirOwnAmountAndNoOther(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $owner = $this->signedIn($app);
        $golf = $this->costlyGolf($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $driver = $this->browserFor($app, 'partner');
        $id = (string) $golf->id;

        $driver->get('/vehicles/' . $id . '/fuel/new');
        $saved = $driver->post('/vehicles/' . $id . '/fuel/new', [
            'filled_at' => '2026-09-20T09:15', 'odometer' => '10800', 'fuel' => 'petrol', 'volume' => '40', 'total' => self::MINE,
        ]);
        self::assertSame(303, $saved->getStatusCode(), 'forms still take costs');

        $pages = ['/', '/garage', '/history', '/upcoming', '/reports', '/reports/ownership', '/reports/true-cost',
            '/vehicles/' . $id, '/vehicles/' . $id . '/fuel', '/vehicles/' . $id . '/maintenance',
            '/vehicles/' . $id . '/documents', '/vehicles/' . $id . '/odometer', '/vehicles/' . $id . '/expenses',
            '/vehicles/' . $id . '/history', '/vehicles/' . $id . '/history/print?costs=1',
            '/upcoming.csv', '/reports/export.csv', '/reports/ownership.csv', '/reports/true-cost.csv'];
        $seen = '';
        foreach ($pages as $page) {
            $response = $driver->get($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            $body = self::body($response);
            foreach (self::OWNERS as $amount) {
                self::assertStringNotContainsString($amount, $body, $page . ' shows ' . $amount);
            }
            $seen .= $body;
        }
        self::assertStringContainsString(self::MINE, self::body($driver->get('/vehicles/' . $id . '/fuel')), 'their own amount');
        self::assertStringContainsString('Excludes 1 vehicle shared without costs', self::body($driver->get('/reports')));
        foreach (['/valuations', '/export/fuel.csv', '/sale-pack', '/edit', '/import/fuel'] as $managed) {
            self::assertSame(403, $driver->get('/vehicles/' . $id . $managed)->getStatusCode(), $managed);
        }

        $api = $this->api($app, $this->apiKey($app, $member));
        $apiBodies = implode("\n", array_map(
            static fn (string $path): string => self::body($api->get($path)),
            ['/vehicles', '/vehicles/' . $id, '/vehicles/' . $id . '/summary', '/vehicles/' . $id . '/fuel',
                '/vehicles/' . $id . '/maintenance', '/vehicles/' . $id . '/documents', '/upcoming'],
        ));
        foreach (['71.370', '187.430', '243.190', '14250.370'] as $amount) {
            self::assertStringNotContainsString($amount, $apiBodies, 'the API shows ' . $amount);
        }
        self::assertStringContainsString('61.230', $apiBodies, 'the API shows their own fill-up\'s amount');
        self::assertSame(403, $api->get('/vehicles/' . $id . '/expenses')->getStatusCode());
        self::assertSame(403, $api->get('/vehicles/' . $id . '/true-cost')->getStatusCode());

        // Control: the owner sees every amount.
        $all = implode("\n", array_map(static fn (string $page): string => self::body($owner->get($page)), $pages));
        foreach (self::OWNERS as $amount) {
            $valuations = self::body($owner->get('/vehicles/' . $id . '/valuations'));
            self::assertStringContainsString($amount, $all . $valuations, $amount);
        }
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function costlyGolf(App $app): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'GO19 ABC',
            purchaseDate: new DateTimeImmutable('2025-03-01', new DateTimeZone('UTC')),
            purchasePrice: '14250.37',
        ));
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '55.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '44.5', '71.37');
        $this->maintenance($app, $golf, '2026-09-05', 'Annual service', '187.43', '10200');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '243.19');
        $this->expense($app, $golf, '2026-09-12', '12.91', ExpenseCategory::Parking);
        $this->service($app, ValuationService::class)->create(
            $golf,
            new VehicleValuationData(new DateTimeImmutable('2026-09-15', new DateTimeZone('UTC')), '9876.54', 'Dealer'),
        );

        return $golf;
    }
}
