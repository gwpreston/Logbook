<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ConfigurableVehicleAccess;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Logbook\Tests\Support\VehicleRoutes;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * With a configurable policy in place of the single-owner one, every
 * vehicle route and every cross-vehicle read follows the policy (spec.md
 * §5 *Access policy*): no access is a 404, view only is a 403 on anything
 * more, no costs shows no amount anywhere, and a vehicle of someone else's
 * that the policy grants shows up. So Phase 19 only needs a new policy.
 */
final class AccessPolicyTest extends AppTestCase
{
    use CostFixtures;
    use VehicleRoutes;

    /** Amounts no other figure on these pages can produce by accident. */
    private const array AMOUNTS = ['14,250.37', '61.37', '187.43', '243.19', '12.91'];

    public function testWithoutAccessEveryVehicleRouteIsNotFound(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $access->set($golf);

        foreach ($this->vehicleRoutes($app) as $route) {
            $response = $this->requestRoute($browser, $route, $golf);
            self::assertSame(404, $response->getStatusCode(), $route->getPattern());
        }
        self::assertStringNotContainsString('/vehicles/' . $golf->id, self::body($browser->get('/garage')));
        self::assertStringNotContainsString('Golf', self::body($browser->get('/')));
    }

    public function testViewOnlyRefusesEverythingElseWithAForbiddenPage(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10000', '44.5', '61.37');
        $access->set($golf, VehicleAbility::View);

        foreach ($this->vehicleRoutes($app) as $route) {
            $needs = VehicleAbility::from($route->getArgument(VehicleAccessMiddleware::ABILITY) ?? '');
            $status = $this->requestRoute($browser, $route, $golf)->getStatusCode();
            if ($needs === VehicleAbility::View) {
                self::assertNotSame(403, $status, $route->getPattern());
            } else {
                self::assertSame(403, $status, $route->getPattern() . ' needs ' . $needs->value);
            }
        }

        $forbidden = $browser->get('/vehicles/' . $golf->id . '/edit');
        self::assertStringContainsString('you are not allowed to make this change', self::body($forbidden));
        self::assertSame(200, $browser->get('/vehicles/' . $golf->id)->getStatusCode());
        self::assertSame(200, $browser->get('/vehicles/' . $golf->id . '/fuel')->getStatusCode());
    }

    public function testWithoutCostsNoAmountIsShownAnywhere(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->costlyGolf($app);

        // Control: with every ability, each amount is on at least one page.
        $shown = implode("\n", array_map(fn (string $url): string => $this->page($browser, $url), $this->pages($golf)));
        foreach (self::AMOUNTS as $amount) {
            self::assertStringContainsString($amount, $shown, 'the control shows ' . $amount);
        }

        $access->except($golf, VehicleAbility::ViewCosts);
        $leaks = [];
        foreach ($this->pages($golf) as $url) {
            $body = $this->page($browser, $url);
            foreach (self::AMOUNTS as $amount) {
                $at = strpos($body, $amount);
                if ($at !== false) {
                    $leaks[] = $url . ': …' . preg_replace('/\s+/', ' ', substr($body, max(0, $at - 160), 200)) . '…';
                }
            }
        }
        self::assertSame([], $leaks);
        foreach (['/expenses', '/valuations', '/export/fuel.csv'] as $costPage) {
            self::assertSame(403, $browser->get('/vehicles/' . $golf->id . $costPage)->getStatusCode(), $costPage);
        }
        self::assertStringContainsString('Golf', $this->page($browser, '/vehicles/' . $golf->id), 'the vehicle still shows');
    }

    public function testReportsCountOnlyVehiclesWhoseCostsCanBeSeen(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->costlyGolf($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->expense($app, $polo, '2026-09-12', '33.33');
        $access->except($golf, VehicleAbility::ViewCosts);

        $reports = $this->page($browser, '/reports');
        self::assertStringContainsString('33.33', $reports);
        self::assertStringNotContainsString('12.91', $reports);
        self::assertStringNotContainsString('value="' . $golf->id . '"', $reports, 'nor in the vehicle filter');
        self::assertStringContainsString('value="' . $polo->id . '"', $reports);
    }

    public function testAVehicleOfSomeoneElseThePolicyGrantsIsReachable(): void
    {
        [$app, $access] = $this->appWithPolicy();
        $browser = $this->signedIn($app);
        $theirs = $this->service($app, VehicleService::class)
            ->create($this->otherOwner($app), new VehicleData(VehicleType::Car, 'Skoda', 'Octavia', FuelType::Diesel));

        self::assertSame(404, $browser->get('/vehicles/' . $theirs->id)->getStatusCode(), 'not without a grant');

        $access->set($theirs, VehicleAbility::View, VehicleAbility::Log);
        self::assertSame(200, $browser->get('/vehicles/' . $theirs->id)->getStatusCode());
        self::assertStringContainsString('Octavia', self::body($browser->get('/garage')));
        self::assertSame(200, $browser->get('/vehicles/' . $theirs->id . '/odometer/new')->getStatusCode());
        self::assertSame(403, $browser->get('/vehicles/' . $theirs->id . '/edit')->getStatusCode());

        $access->set($theirs, VehicleAbility::View, VehicleAbility::Manage);
        $browser->get('/vehicles/' . $theirs->id . '/edit');
        $saved = $browser->post('/vehicles/' . $theirs->id . '/edit', [
            'type' => 'car',
            'make' => 'Skoda',
            'model' => 'Superb',
            'fuel_type' => 'diesel',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $saved = $this->service($app, VehicleRepository::class)->findById($theirs->id);
        self::assertSame('Superb', $saved?->data->model, 'saved under its own owner');
    }

    /**
     * @return array{App<ContainerInterface>, ConfigurableVehicleAccess}
     */
    private function appWithPolicy(): array
    {
        $app = $this->createApp();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $access = new ConfigurableVehicleAccess($this->service($app, VehicleRepository::class));
        $container->set(VehicleAccess::class, $access);

        return [$app, $access];
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
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '44.5', '61.37');
        $this->maintenance($app, $golf, '2026-09-05', 'Annual service', '187.43', '10200');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '243.19');
        $this->expense($app, $golf, '2026-09-12', '12.91', ExpenseCategory::Parking);

        return $golf;
    }

    /**
     * Every page that shows the vehicle or its figures.
     *
     * @return list<string>
     */
    private function pages(Vehicle $golf): array
    {
        $id = (string) $golf->id;

        return [
            '/vehicles/' . $id,
            '/vehicles/' . $id . '/fuel',
            '/vehicles/' . $id . '/maintenance',
            '/vehicles/' . $id . '/documents',
            '/vehicles/' . $id . '/odometer',
            '/vehicles/' . $id . '/history',
            '/vehicles/' . $id . '/history/print',
            '/vehicles/' . $id . '/sale-pack',
            '/',
            '/?vehicle=' . $id,
            '/garage',
            '/history',
            '/upcoming',
            '/upcoming.csv',
            '/reports',
            '/reports/export.csv',
            '/reports/ownership',
            '/reports/ownership.csv',
            '/reminders',
        ];
    }

    private function page(TestBrowser $browser, string $url): string
    {
        $response = $browser->get($url);
        self::assertSame(200, $response->getStatusCode(), $url);

        return self::body($response);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function otherOwner(App $app): User
    {
        return $this->service($app, UserRepository::class)->insert(
            'other',
            $this->service($app, PasswordHasher::class)->hash(self::PASSWORD),
            'Sam Other',
            DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
    }
}
