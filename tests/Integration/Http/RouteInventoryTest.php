<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Middleware\InstanceAccessMiddleware;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Demo\DemoRoutes;
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
        // Phase 28.1: the scheduler's secret URL (the token is the key; 404 while off).
        'scheduler.url',
        // Phase 33.1: *Forgotten password* and email confirmation links (the token is the key).
        'password.forgot',
        'email.confirm',
    ];

    /**
     * Signed in (or an API key), nothing but one's own account and settings (or a
     * form that names no vehicle yet).
     */
    private const array PERSONAL = [
        'logout',
        // Phase 28.1: any signed-in page's scheduler beacon (404 while off).
        'scheduler.tick',
        'api.me',
        'api.trips.claim',
        'settings.api_keys',
        'settings.api_keys.revoke',
        // Phase 33.1: one's own email address and avatar, and anyone's avatar picture (#161).
        'settings.email',
        'settings.email.action',
        'settings.avatar',
        'settings.avatar.action',
        'users.avatar',
        'dashboard.layout',
        // Phase 30.2: the Cheapest fuel widget's place, one of the user's own.
        'dashboard.cheapest_fuel',
        'log.chooser',
        'vehicles.create',
        'settings',
        'profile',
        'settings.reminders',
        'settings.reminders.test',
        'settings.reminders.calendar',
        // Phase 36.2: one's own notification channels, by kind (never an id).
        'settings.notifications',
        'settings.notifications.channel',
        'settings.notifications.switch',
        'settings.notifications.remove',
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
        // Phase 26.1: one's own *Use AI features* switch.
        'settings.ai_use',
        // Phase 26.2: Ask Logbook, one's own threads only (404 unless Ask is available).
        'ask',
        // Phase 33.4: the Insights page and one's own AI insights (Refresh: 404 unless Ask is available).
        'insights',
        'insights.refresh',
        'ask.post',
        'ask.progress',
        'ask.retention',
        'ask.thread',
        'ask.thread.delete',
        'ask.threads.delete',
        'ask.feedback',
        // Phase 26.3: a draft card's buttons, one's own drafts only; the kind's ability is checked at the press.
        'ask.draft',
        // Phase 26.5: an MCP draft's buttons, likewise; and the MCP endpoint, whose key's user is
        // judged by each tool, resource and prompt with the pages' access policy.
        'drafts.action',
        'mcp',
        // Phase 26.4: scanning, one's own scans only (404 unless scanning is available); the vehicle's
        // ability is checked by the form a scan opens, the card (Manage) and the vehicle page (Manage).
        'scan',
        'scan.result',
        'scan.file',
        'scan.reminders',
        'scan.vehicle',
        // Phase 30.1: the user's own places (private to them).
        'settings.places',
        'settings.places.create',
        'settings.places.edit',
        'settings.places.delete',
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
        'reminders.calendar',
        'reminders.create',
        'reports.index',
        'reports.export',
        'reports.ownership',
        'reports.ownership.export',
        'reports.true_cost',
        'reports.true_cost.export',
        'api.vehicles',
        'api.upcoming',
        'api.reminders',
        // Phase 22: the user's own claim, over the vehicles they may see.
        'trips.claim',
        'trips.claim.export',
        // Phase 27.1: every incident on the vehicles the user can see.
        'incidents.history',
        'incidents.history.export',
        'api.incidents.history',
        // Phase 30.1: stations are shared records; what was paid at them comes from the
        // fill-ups on the vehicles the user can see (StationService::visits), amounts only
        // where they may see them; editing and merging check the creator or an admin.
        'stations.index',
        'stations.search',
        'stations.duplicates',
        'stations.create',
        'stations.show',
        'stations.edit',
        'stations.favourite',
        'stations.merge',
        'api.stations.index',
        'api.stations.show',
        // Phase 30.2: Cheapest near me searches the shared provider list for one of the user's
        // fleet vehicles (NearForm::vehicles); linking checks the creator or an admin; alerts
        // are the user's own, on their favourites. All 404 until a provider is enabled.
        'stations.near',
        'stations.near.add',
        'api.fuel_prices.near',
        'stations.link',
        'stations.alerts',
        // Phase 31: importing from another app; the target vehicle must be one the user can
        // manage (VehicleService::listWith Manage, else 404), a new vehicle is their own.
        'import_app.upload',
        'import_app.map',
    ];

    /**
     * Every route a demo visitor may use (spec.md §7.36, Phase 35.1). The rest are blocked: they are
     * DemoRoutes::BLOCKED, or the REST API (but its description). A new route must choose.
     */
    private const array DEMO_ALLOWED = [
        'health', 'pwa.manifest', 'pwa.worker', 'pwa.offline', 'calendar.feed', 'scheduler.url', 'api.openapi',
        'setup', 'login', 'diagnostics.deep-link', 'home', 'logout', 'dashboard.layout', 'dashboard.cheapest_fuel',
        'log.chooser', 'log.pick', 'garage', 'vehicles.create', 'vehicles.show', 'vehicles.edit',
        'vehicles.first_inspection', 'attention.hide', 'vehicles.delete', 'vehicles.archive', 'vehicles.restore',
        'vehicles.photo', 'vehicles.sharing', 'vehicles.sharing.add', 'vehicles.sharing.change',
        'vehicles.sharing.mine', 'vehicles.transfer', 'history.fleet', 'history.vehicle', 'history.print',
        'sale_pack.show', 'sale_pack.paperwork', 'upcoming', 'upcoming.export', 'odometer.index',
        'odometer.create', 'odometer.edit', 'odometer.delete', 'fuel.quick', 'fuel.index', 'fuel.create',
        'fuel.edit', 'fuel.delete', 'fuel.economy', 'maintenance.index', 'maintenance.create', 'maintenance.edit',
        'maintenance.delete', 'maintenance.schedules.create', 'maintenance.schedules.edit',
        'maintenance.schedules.delete', 'tyres.index', 'tyres.change', 'tyres.changes.edit',
        'tyres.changes.delete', 'tyres.sets.edit', 'tyres.sets.delete', 'tyres.edit', 'tyres.delete',
        'trips.index', 'trips.create', 'trips.edit', 'trips.delete', 'incidents.index', 'incidents.create',
        'incidents.show', 'incidents.edit', 'incidents.delete', 'incidents.links', 'finance.index',
        'finance.create', 'finance.show', 'finance.edit', 'finance.delete', 'finance.payments',
        'finance.events.delete', 'finance.quotes', 'finance.quotes.delete', 'finance.schedule', 'finance.end',
        'compliance.index', 'compliance.create', 'compliance.edit', 'compliance.delete', 'expenses.index',
        'expenses.create', 'expenses.edit', 'expenses.delete', 'vehicles.ownership', 'valuations.index',
        'valuations.create', 'valuations.edit', 'valuations.delete', 'export.module', 'attachments.show',
        'attachments.delete', 'reminders.index', 'reminders.calendar', 'reminders.create', 'reminders.edit',
        'reminders.delete', 'reminders.status', 'reports.index', 'reports.export', 'reports.ownership',
        'reports.ownership.export', 'reports.true_cost', 'reports.true_cost.export', 'settings', 'profile',
        'settings.reminders', 'settings.notifications', 'settings.modules', 'settings.tyres', 'stations.index', 'stations.search',
        'stations.duplicates', 'stations.near', 'stations.near.add', 'stations.create', 'stations.show',
        'stations.edit', 'stations.favourite', 'stations.merge', 'stations.link', 'stations.alerts',
        'settings.places', 'settings.places.create', 'settings.places.edit', 'settings.places.delete',
        'incidents.history', 'incidents.history.export', 'trips.claim', 'trips.claim.export', 'settings.trips',
        'settings.trips.journeys.create', 'settings.trips.journeys.edit', 'settings.trips.journeys.delete',
        'settings.trips.journeys.move', 'settings.trips.rates.create', 'settings.trips.rates.edit',
        'settings.trips.rates.delete', 'notices.dismiss', 'scheduler.tick', 'settings.preferences', 'users.avatar',
        'settings.theme', 'insights',
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

    public function testEveryRouteDeclaresItsPlaceInTheDemo(): void
    {
        $names = [];
        $neither = [];
        $both = [];
        foreach ($this->routes($this->createApp()) as $route) {
            $name = (string) $route->getName();
            $names[] = $name;
            $blocked = DemoRoutes::isBlocked($name);
            $allowed = in_array($name, self::DEMO_ALLOWED, true);
            if (!$blocked && !$allowed) {
                $neither[] = sprintf('%s %s (%s)', implode('|', $route->getMethods()), $route->getPattern(), $name);
            }
            if ($blocked && $allowed) {
                $both[] = $name;
            }
        }

        self::assertSame([], $neither, "Routes that say nothing about the demo (spec.md §7.36): add them to
DemoRoutes::BLOCKED or to DEMO_ALLOWED in this test:
" . implode("
", $neither));
        self::assertSame([], $both, 'Routes both blocked and allowed in the demo');
        $stale = array_values(array_diff(self::DEMO_ALLOWED, $names));
        self::assertSame([], $stale, 'DEMO_ALLOWED names a route that does not exist');
        $stale = array_values(array_diff(DemoRoutes::BLOCKED, $names));
        self::assertSame([], $stale, 'DemoRoutes::BLOCKED names a route that does not exist');
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
            $path = (string) preg_replace('/\{[a-z]+:\[0-9\]\+\}/', '1', $route->getPattern());
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
            'settings.fuel_prices' => InstanceAbility::ManageFuelPrices,
            'backup.index' => InstanceAbility::Backup,
            'backup.download' => InstanceAbility::Backup,
            'backup.restore' => InstanceAbility::Restore,
            'backup.restore.confirm' => InstanceAbility::Restore,
            // Phase 28.1: a scheduled backup file.
            'backup.file' => InstanceAbility::Backup,
            // Phase 28.1: Settings → Jobs and the dashboard's admin notices, 404 to non-admins.
            'settings.jobs' => InstanceAbility::RunJobs,
            'settings.jobs.triggers' => InstanceAbility::RunJobs,
            'settings.jobs.url_token' => InstanceAbility::RunJobs,
            'settings.jobs.backup' => InstanceAbility::RunJobs,
            'settings.jobs.run' => InstanceAbility::RunJobs,
            'settings.jobs.run.status' => InstanceAbility::RunJobs,
            'settings.jobs.run_now' => InstanceAbility::RunJobs,
            'settings.jobs.started' => InstanceAbility::RunJobs,
            'settings.updates' => InstanceAbility::RunJobs,
            'notices.dismiss' => InstanceAbility::RunJobs,
            // Phase 36.1: the email server.
            'settings.delivery' => InstanceAbility::ManageNotifications,
            'settings.delivery.remove' => InstanceAbility::ManageNotifications,
            'settings.users' => InstanceAbility::ManageUsers,
            'settings.users.change' => InstanceAbility::ManageUsers,
            'settings.users.confirm' => InstanceAbility::ManageUsers,
            'settings.users.add' => InstanceAbility::ManageUsers,
            'settings.users.delete' => InstanceAbility::ManageUsers,
            'settings.users.transfer' => InstanceAbility::ManageUsers,
            'settings.users.revoke' => InstanceAbility::ManageUsers,
            'settings.users.identity.remove' => InstanceAbility::ManageUsers,
            // Phase 26.1: Settings → AI, which answers 404 to non-admins.
            'settings.ai' => InstanceAbility::ManageAi,
            'settings.ai.tasks' => InstanceAbility::ManageAi,
            'settings.ai.this_host' => InstanceAbility::ManageAi,
            'settings.ai.connections.create' => InstanceAbility::ManageAi,
            'settings.ai.connections.show' => InstanceAbility::ManageAi,
            'settings.ai.connections.edit' => InstanceAbility::ManageAi,
            'settings.ai.connections.delete' => InstanceAbility::ManageAi,
            'settings.ai.connections.acknowledge' => InstanceAbility::ManageAi,
            'settings.ai.connections.models' => InstanceAbility::ManageAi,
            'settings.ai.connections.test' => InstanceAbility::ManageAi,
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
