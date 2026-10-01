<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Middleware\InstanceAccessMiddleware;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteInterface;

/**
 * Every route says what it needs (spec.md §5 *Route inventory*): public,
 * signed-in only (one's own settings), a fleet page (lists the access
 * policy's vehicles), an install-wide page (an InstanceAbility), or a
 * vehicle route with a declared VehicleAbility. Adding a route without
 * classifying it fails here and names the route.
 */
final class RouteInventoryTest extends AppTestCase
{
    /**
     * No sign-in: machine endpoints, the installable app, setup and sign-in, the
     * calendar feed and one-time links by token, the API's own description.
     */
    private const array PUBLIC = [
        'health',
        'api.openapi',
        'pwa.manifest',
        'pwa.worker',
        'pwa.offline',
        'calendar.feed',
        'setup',
        'login',
        'invite.accept',
        'diagnostics.deep-link',
        // Phase 23.1: single sign-on and the break-glass link.
        'login.link',
        'oidc.start',
        'oidc.callback',
    ];

    /**
     * Signed in (or an API key), nothing but one's own account and settings (or a
     * form that names no vehicle yet).
     */
    private const array PERSONAL = [
        'logout',
        'api.me',
        'api.trips.claim',
        'settings.api_keys',
        'settings.api_keys.revoke',
        'dashboard.layout',
        'log.chooser',
        'vehicles.create',
        'settings',
        'settings.reminders',
        'settings.reminders.test',
        'settings.reminders.calendar',
        'settings.tyres',
        // Phase 22: the user's own trip settings, saved journeys and rates.
        'settings.trips',
        'settings.trips.journeys.create',
        'settings.trips.journeys.edit',
        'settings.trips.journeys.delete',
        'settings.trips.journeys.move',
        'settings.trips.rates.create',
        'settings.trips.rates.edit',
        'settings.trips.rates.delete',
        'settings.preferences',
        'settings.password',
        'settings.theme',
        // Phase 23.1: one's own single sign-on account, the welcome after SSO
        // created it, and the saved journeys (API).
        'settings.sso.link',
        'settings.sso.unlink',
        'welcome',
        'api.journeys',
        // Phase 23.2: linking one's own proxy account.
        'proxy.link',
    ];

    /** Signed in; every vehicle they show comes from the policy's visible ids (VehicleService::listFleet / listWith). */
    private const array FLEET = [
        'home',
        'garage',
        'history.fleet',
        'upcoming',
        'upcoming.export',
        'log.pick',
        'fuel.quick',
        'reminders.index',
        'reminders.create',
        'reports.index',
        'reports.export',
        'reports.ownership',
        'reports.ownership.export',
        'api.vehicles',
        'api.upcoming',
        'api.reminders',
        // Phase 22: the user's own claim, over the vehicles they may see.
        'trips.claim',
        'trips.claim.export',
    ];

    public function testEveryRouteIsClassified(): void
    {
        $routes = $this->routes($this->createApp());

        $unclassified = [];
        foreach ($routes as $route) {
            if (self::classify($route) === null) {
                $methods = implode('|', $route->getMethods());
                $unclassified[] = sprintf('%s %s (%s)', $methods, $route->getPattern(), $route->getName() ?? 'no name');
            }
        }

        self::assertSame([], $unclassified, "Routes that declare no access (declare an ability in config/routes.php,\n"
            . "or add them to this test's lists):\n" . implode("\n", $unclassified));
    }

    public function testTheListsNameOnlyRoutesThatExist(): void
    {
        $names = array_map(static fn (RouteInterface $route): ?string => $route->getName(), $this->routes($this->createApp()));

        self::assertSame([], array_values(array_diff([...self::PUBLIC, ...self::PERSONAL, ...self::FLEET], $names)));
    }

    public function testEveryVehicleAndReminderRouteDeclaresAnAbility(): void
    {
        $declared = [];
        foreach ($this->routes($this->createApp()) as $route) {
            $pattern = $route->getPattern();
            if (str_contains($pattern, '{id:') || str_contains($pattern, '{reminder:')) {
                $ability = VehicleAbility::tryFrom($route->getArgument(VehicleAccessMiddleware::ABILITY) ?? '');
                self::assertNotNull($ability, $pattern . ' declares no vehicle ability');
                $declared[(string) $route->getName()] = $ability;
            }
        }

        // The spec's reading of the abilities (spec.md §5), spot-checked.
        self::assertSame(VehicleAbility::View, $declared['vehicles.show']);
        self::assertSame(VehicleAbility::View, $declared['attachments.show']);
        self::assertSame(VehicleAbility::View, $declared['expenses.index'], 'without ViewCosts it lists expenses only');
        self::assertSame(VehicleAbility::ViewCosts, $declared['valuations.index']);
        self::assertSame(VehicleAbility::Manage, $declared['export.module']);
        self::assertSame(VehicleAbility::Log, $declared['fuel.create']);
        // Entry edits declare Log; the Action allows only one's own without Manage (Phase 19).
        self::assertSame(VehicleAbility::Log, $declared['fuel.edit']);
        self::assertSame(VehicleAbility::Log, $declared['attachments.delete']);
        self::assertSame(VehicleAbility::Manage, $declared['valuations.edit']);
        self::assertSame(VehicleAbility::Manage, $declared['vehicles.edit']);
        self::assertSame(VehicleAbility::Manage, $declared['import.upload']);
        self::assertSame(VehicleAbility::Manage, $declared['sale_pack.show']);
        self::assertSame(VehicleAbility::Own, $declared['vehicles.archive']);
        self::assertSame(VehicleAbility::Own, $declared['vehicles.delete']);
        self::assertSame(VehicleAbility::Log, $declared['reminders.status']);
        self::assertSame(VehicleAbility::Manage, $declared['reminders.edit']);
        // The API's vehicle routes are checked by the same middleware (spec.md §7.20).
        self::assertSame(VehicleAbility::View, $declared['api.vehicles.summary']);
        self::assertSame(VehicleAbility::View, $declared['api.fuel.index']);
        self::assertSame(VehicleAbility::Log, $declared['api.fuel.create']);
        self::assertSame(VehicleAbility::Log, $declared['api.odometer.create']);
        self::assertSame(VehicleAbility::ViewCosts, $declared['api.expenses.index']);
        self::assertSame(VehicleAbility::View, $declared['api.trips.index']);
        self::assertSame(VehicleAbility::Log, $declared['api.trips.create']);
    }

    public function testEveryApiRouteButItsDescriptionNeedsAKey(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $apiRoutes = array_filter(
            $this->routes($app),
            static fn (RouteInterface $route): bool => str_starts_with($route->getPattern(), '/api/'),
        );
        self::assertNotEmpty($apiRoutes);

        foreach ($apiRoutes as $route) {
            $path = (string) preg_replace('/\{id:[^}]+\}/', '1', $route->getPattern());
            foreach ($route->getMethods() as $method) {
                // Signed in, but no key: a session never opens the API.
                $response = $method === 'GET' ? $browser->get($path) : $browser->post($path, [], [], false);
                $expected = $route->getName() === 'api.openapi' ? 200 : 401;
                self::assertSame($expected, $response->getStatusCode(), $method . ' ' . $path);
            }
        }
    }

    public function testInstancePagesDeclareTheirAbility(): void
    {
        $declared = [];
        foreach ($this->routes($this->createApp()) as $route) {
            $value = $route->getArgument(InstanceAccessMiddleware::ABILITY);
            if ($value !== null) {
                $declared[(string) $route->getName()] = InstanceAbility::from($value);
            }
        }

        self::assertSame([
            'settings.modules' => InstanceAbility::ManageModules,
            'backup.index' => InstanceAbility::Backup,
            'backup.download' => InstanceAbility::Backup,
            'backup.restore' => InstanceAbility::Restore,
            'backup.restore.confirm' => InstanceAbility::Restore,
            'settings.users' => InstanceAbility::ManageUsers,
            'settings.users.change' => InstanceAbility::ManageUsers,
            'settings.users.delete' => InstanceAbility::ManageUsers,
            'settings.users.transfer' => InstanceAbility::ManageUsers,
            'settings.users.revoke' => InstanceAbility::ManageUsers,
            'settings.users.identity.remove' => InstanceAbility::ManageUsers,
        ], $declared);
    }

    public function testAnUnclassifiedRouteIsNamed(): void
    {
        $app = $this->createApp();
        $app->get('/vehicles/{id:[0-9]+}/unlisted', static fn (): ResponseInterface => throw new \LogicException('never runs'))
            ->setName('unlisted');
        $app->get('/somewhere', static fn (): ResponseInterface => throw new \LogicException('never runs'))->setName('somewhere');

        $unclassified = array_values(array_map(
            static fn (RouteInterface $route): ?string => $route->getName(),
            array_filter($this->routes($app), static fn (RouteInterface $route): bool => self::classify($route) === null),
        ));

        self::assertSame(['unlisted', 'somewhere'], $unclassified);
    }

    public function testAVehicleRouteWithoutAnAbilityNeverRuns(): void
    {
        $app = $this->createApp();
        $ran = false;
        // Slim binds group and route closures to the container, so they must not be static.
        $app->group('', function (RouteCollectorProxyInterface $group) use (&$ran): void {
            $group->get('/vehicles/{id:[0-9]+}/unguarded', function () use (&$ran): ResponseInterface {
                $ran = true;
                throw new \LogicException('an unguarded route ran');
            });
        })->add(VehicleAccessMiddleware::class);
        // Routes are added before the first request: the router is built once.
        $browser = $this->signedIn($app);

        $response = $browser->get('/vehicles/1/unguarded');

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($ran);
    }

    private static function classify(RouteInterface $route): ?string
    {
        $name = $route->getName() ?? '';
        $pattern = $route->getPattern();

        return match (true) {
            in_array($name, self::PUBLIC, true) => 'public',
            in_array($name, self::PERSONAL, true) => 'personal',
            in_array($name, self::FLEET, true) => 'fleet',
            InstanceAbility::tryFrom($route->getArgument(InstanceAccessMiddleware::ABILITY) ?? '') !== null => 'instance',
            (str_contains($pattern, '{id:') || str_contains($pattern, '{reminder:'))
                && VehicleAbility::tryFrom($route->getArgument(VehicleAccessMiddleware::ABILITY) ?? '') !== null => 'vehicle',
            default => null,
        };
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<RouteInterface>
     */
    private function routes(App $app): array
    {
        return array_values($app->getRouteCollector()->getRoutes());
    }
}
