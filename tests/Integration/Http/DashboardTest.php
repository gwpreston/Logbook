<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;

/**
 * The widget dashboard: the default order (also the no-JS fallback), a
 * layout arranged with plain forms or by drag and drop and stored per user,
 * archived vehicles kept out, and switched-off modules' widgets hidden.
 * "Today" is 27 Sep 2026.
 */
final class DashboardTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const array DEFAULT_ORDER = [
        'needs_attention', 'reminders', 'coming_up', 'spend', 'recent_fuel', 'fleet', 'efficiency', 'compliance', 'mileage',
        'recent_activity',
    ];

    public function testWidgetsShowTheActiveFleetInTheDefaultOrder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $sold = $this->vehicle($app, 'Ford', 'Fiesta');
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '10000', '40', '58.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10800', '45.461', '66.64');
        $this->expense($app, $golf, '2026-09-20', '2.5', ExpenseCategory::Tolls);
        $this->document($app, $golf, ComplianceType::Insurance, '2025-10-01', '2026-10-05', '420');
        $this->fillUp($app, $sold, '2026-09-12T08:00:00Z', '50000', '30', '45.00');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $sold);

        $html = self::body($browser->get('/'));

        self::assertSame(self::DEFAULT_ORDER, self::widgetOrder($html), 'no saved layout: the default order');
        self::assertStringContainsString('Your vehicles', $html);
        self::assertStringContainsString('Volkswagen Golf', $html);
        self::assertStringContainsString('6,711 mi', $html, 'current odometer (10,800 km)');
        self::assertStringContainsString('1 archived vehicle', $html);
        self::assertStringNotContainsString('Ford Fiesta', $html, 'archived vehicles stay off the dashboard');
        // Spend this month: fuel + toll, not the sold car's fill-up; last month for comparison.
        self::assertStringContainsString('£69.14', $html);
        self::assertStringContainsString('last month £58.00', $html);
        self::assertStringContainsString('September 2026 so far', $html);
        // Recent fuel with the full-to-full economy (800 km on 45.461 L = 49.7 mpg UK).
        self::assertStringContainsString('49.7 mpg', $html);
        // Compliance: the policy expiring in 8 days.
        self::assertStringContainsString('Expires in 8 days', $html);
        // Upcoming reminders come from the same document.
        self::assertStringContainsString('Upcoming reminders', $html);
        // Customise is a link: works without JS.
        self::assertStringContainsString('href="/?customise=1"', $html);
        self::assertStringNotContainsString('data-dashboard-sortable', $html);
    }

    public function testArrangingWithPlainFormsPersistsPerUser(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->vehicle($app);
        $owner = $this->owner($app);

        $customise = self::body($browser->get('/?customise=1'));
        self::assertStringContainsString('data-dashboard-sortable', $customise);
        self::assertStringContainsString('Move Upcoming reminders up', $customise);
        self::assertStringContainsString('Hide Documents', $customise);
        self::assertStringContainsString('action="/dashboard/layout"', $customise);

        $moved = $browser->post('/dashboard/layout', ['widget' => 'spend', 'move' => 'up']);
        self::assertSame(303, $moved->getStatusCode());
        self::assertSame('/?customise=1#widget-spend', $moved->getHeaderLine('Location'));
        $browser->post('/dashboard/layout', ['widget' => 'spend', 'move' => 'up']);
        $browser->post('/dashboard/layout', ['widget' => 'spend', 'move' => 'up']);
        $browser->post('/dashboard/layout', ['widget' => 'spend', 'move' => 'up']); // already first: no change
        $browser->post('/dashboard/layout', ['widget' => 'fleet', 'move' => 'up']);
        $browser->post('/dashboard/layout', ['widget' => 'efficiency', 'toggle' => '1']);

        $settings = $this->service($app, SettingRepository::class);
        $stored = $settings->find(DashboardLayoutStore::SETTING, SettingScope::User, $owner->id);
        self::assertNotNull($stored, 'kept as a user-scoped settings row');
        self::assertSame([
            'order' => [
                'spend', 'needs_attention', 'reminders', 'coming_up', 'fleet', 'recent_fuel',
                'efficiency', 'compliance', 'mileage', 'recent_activity', 'business_mileage',
            ],
            'hidden' => ['efficiency'],
        ], $stored->value);

        $html = self::body($browser->get('/'));
        $arranged = [
            'spend', 'needs_attention', 'reminders', 'coming_up', 'fleet', 'recent_fuel', 'compliance', 'mileage',
            'recent_activity',
        ];
        self::assertSame($arranged, self::widgetOrder($html), 'hidden: not shown');
        // Customise mode still lists it, folded, so it can be shown again.
        self::assertContains('efficiency', self::widgetOrder(self::body($browser->get('/?customise=1'))));

        // A second browser session sees the same layout: it is stored, not in the browser.
        $again = new TestBrowser($app);
        $again->get('/login');
        $again->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame($arranged, self::widgetOrder(self::body($again->get('/'))));

        // Reset: back to the default, and the row is gone.
        $browser->post('/dashboard/layout', ['reset' => '1']);
        self::assertSame(self::DEFAULT_ORDER, self::widgetOrder(self::body($browser->get('/'))));
        self::assertNull($settings->find(DashboardLayoutStore::SETTING, SettingScope::User, $owner->id));
    }

    public function testDragAndDropSavesTheOrderWithoutAPageLoad(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->vehicle($app);
        $browser->get('/?customise=1');

        $response = $browser->post(
            '/dashboard/layout',
            ['order' => 'compliance,efficiency,bogus,fleet'],
        );
        self::assertSame(303, $response->getStatusCode(), 'a plain post is redirected');

        self::assertSame(
            [
                'compliance', 'efficiency', 'fleet', 'needs_attention', 'reminders', 'coming_up', 'spend', 'recent_fuel',
                'mileage', 'recent_activity',
            ],
            self::widgetOrder(self::body($browser->get('/'))),
            'unknown ids dropped, the rest appended',
        );

        // Without a CSRF token nothing changes.
        self::assertSame(400, $browser->post('/dashboard/layout', ['reset' => '1'], [], false)->getStatusCode());
    }

    public function testTheScriptGetsNoContent(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->vehicle($app);
        $browser->get('/?customise=1');

        $response = $browser->post('/dashboard/layout', ['order' => 'spend,fleet'], [], true, ['X-Requested-With' => 'fetch']);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('spend', self::widgetOrder(self::body($browser->get('/')))[0]);
    }

    public function testSwitchedOffModulesHideTheirWidgets(): void
    {
        $app = $this->createApp(['FEATURES_COMPLIANCE' => 'false']);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        $html = self::body($browser->get('/'));
        self::assertSame(
            [
                'needs_attention', 'reminders', 'coming_up', 'spend', 'recent_fuel', 'fleet', 'efficiency', 'mileage',
                'recent_activity',
            ],
            self::widgetOrder($html),
        );

        $this->service($app, SettingRepository::class)->save(
            FeatureToggles::SETTING,
            ['fuel' => false, 'compliance' => true, 'reports' => false],
            SettingScope::Global,
        );
        $html = self::body($browser->get('/?customise=1'));
        self::assertSame(
            ['needs_attention', 'reminders', 'coming_up', 'fleet', 'compliance', 'mileage', 'recent_activity'],
            self::widgetOrder($html),
            'the stored setting wins',
        );
    }

    public function testWithoutVehiclesTheDashboardInvitesYouToAddOne(): void
    {
        $html = self::body($this->signedIn($this->createApp())->get('/'));

        self::assertStringContainsString('No vehicles yet', $html);
        self::assertStringContainsString('href="/vehicles/new"', $html);
        self::assertSame([], self::widgetOrder($html));
    }

    /**
     * @return list<string>
     */
    private static function widgetOrder(string $html): array
    {
        preg_match_all('/<section class="widget[^"]*" id="widget-[a-z_]+" data-widget="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }
}
