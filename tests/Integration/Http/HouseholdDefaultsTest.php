<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Dashboard\DashboardLayout;
use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Sharing\SharedIntroPending;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserAdmin;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Household defaults (Phase 49.2, spec.md §7.8, §7.9, §7.10): the Household
 * layout and fewer modules for new users and installs, the shared user's
 * first visit, and nothing changed for what exists already.
 */
final class HouseholdDefaultsTest extends AppTestCase
{
    use CostFixtures;

    private const array HOUSEHOLD = ['needs_attention', 'coming_up', 'spend', 'monthly_expenses', 'recent_activity'];

    public function testNewInstallStartsOnFewerModulesAndTheHouseholdLayout(): void
    {
        $app = $this->createApp();
        $browser = $this->runSetup($app);

        $features = $this->service($app, FeatureToggles::class);
        $enabled = [
            Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports, Feature::AiAsk,
        ];
        foreach ($enabled as $on) {
            self::assertTrue($features->isEnabled($on), $on->value);
        }
        $disabled = [
            Feature::Tyres, Feature::Finance, Feature::Incidents, Feature::Issues, Feature::Stations, Feature::Trips,
        ];
        foreach ($disabled as $off) {
            self::assertFalse($features->isEnabled($off), $off->value);
        }
        self::assertCount(6, $features->offSinceInstall());

        $layout = $this->service($app, DashboardLayoutStore::class)->load($this->owner($app)->id);
        self::assertSame(self::HOUSEHOLD, array_map(
            static fn ($w): string => $w->value,
            array_slice($layout->order, 0, 5),
        ));
        self::assertTrue($layout->household);
        self::assertCount(count($layout->order) - 5, $layout->hidden);
        self::assertSame(10, $layout->activityRows(8));

        // Settings → Modules says why they are off, then stops once one is switched on.
        $page = self::body($browser->get('/settings/modules'));
        self::assertSame(6, substr_count($page, 'Switched off when Logbook was installed; switch on any time.'));
        $browser->post('/settings/modules', [
            'fuel' => '1', 'maintenance' => '1', 'compliance' => '1', 'reminders' => '1', 'reports' => '1',
            'tyres' => '1', 'ai_ask' => '1', 'ai_actions' => '1', 'ai_scan' => '1',
        ]);
        self::assertCount(5, $this->service($app, FeatureToggles::class)->offSinceInstall());
    }

    public function testSetupKeepsAnExplicitModuleVariableAndNeverOverwritesFeatures(): void
    {
        $app = $this->createApp(['FEATURES_TYRES' => 'true']);
        $this->runSetup($app);
        $features = $this->service($app, FeatureToggles::class);
        self::assertTrue($features->isEnabled(Feature::Tyres));
        self::assertNotContains(Feature::Tyres, $features->offSinceInstall());

        $settings = $this->service($app, SettingRepository::class);
        $settings->save(FeatureToggles::SETTING, ['tyres' => false, 'fuel' => true]);
        $features->startNewInstall();
        self::assertEquals(['tyres' => false, 'fuel' => true], $settings->find(FeatureToggles::SETTING)?->value, 'MySQL may reorder JSON keys');
    }

    public function testExistingUsersAndInstallsKeepTheFullLayoutAndAllModules(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        self::assertTrue($this->service($app, DashboardLayoutStore::class)->load($this->owner($app)->id)->isDefault());
        self::assertTrue($this->service($app, FeatureToggles::class)->isEnabled(Feature::Tyres));
        self::assertSame([], $this->service($app, FeatureToggles::class)->offSinceInstall());
        $modules = self::body($browser->get('/settings/modules'));
        self::assertStringNotContainsString('Switched off when Logbook was installed', $modules);
        $this->vehicle($app);
        self::assertStringContainsString('/log/new/incident', self::body($browser->get('/log/new')));
    }

    public function testResetLayoutOffersHouseholdAndTheFullLayout(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $store = $this->service($app, DashboardLayoutStore::class);
        $owner = $this->owner($app);

        $this->vehicle($app);
        $customise = self::body($browser->get('/?customise=1'));
        self::assertStringContainsString('Reset to Household layout', $customise);
        self::assertStringContainsString('Reset to all widgets', $customise);

        $browser->post('/dashboard/layout', ['reset' => 'household']);
        self::assertTrue($store->load($owner->id)->household);
        $browser->post('/dashboard/layout', ['widget' => 'spend', 'move' => 'up']);
        self::assertTrue($store->load($owner->id)->household, 'moving keeps the preset');

        $browser->post('/dashboard/layout', ['reset' => '1']);
        self::assertTrue($store->load($owner->id)->isDefault());
        self::assertFalse(DashboardLayout::fromArray(['order' => [], 'hidden' => [], 'preset' => 'other'])->household);
    }

    public function testMembersAndInvitedUsersStartOnTheHouseholdLayout(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $user = $this->service($app, UserAdmin::class)->addUser(
            $this->owner($app),
            'newbie',
            'New Person',
            'new@example.test',
            false,
            $this->owner($app)->preferences,
        );

        self::assertTrue($this->service($app, DashboardLayoutStore::class)->load($user->id)->household);
        self::assertNotNull($this->service($app, UserRepository::class)->find($user->id));
    }

    public function testChooserSaysWhyAModuleIsAbsentToAnAdminOnly(): void
    {
        $app = $this->createApp();
        $browser = $this->runSetup($app);
        $vehicle = $this->vehicle($app);

        $page = self::body($browser->get('/log/new'));
        self::assertStringContainsString('Incidents are switched off.', $page);
        self::assertStringContainsString('href="/settings/modules"', $page);
        self::assertStringNotContainsString('/log/new/incident', $page);

        $member = $this->createMember($app);
        $this->service($app, SharingService::class)->add($vehicle, $member->username, ShareLevel::Log, false, false);
        $other = $this->browserFor($app, 'partner');
        self::assertStringNotContainsString('Incidents are switched off.', self::body($other->get('/log/new')));
    }

    public function testSharedUserSeesWhatTheShareAllowsOnceAndOnlyForNewShares(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $vehicle = $this->vehicle($app);
        $member = $this->createMember($app);
        $sharing = $this->service($app, SharingService::class);
        $pending = $this->service($app, SharedIntroPending::class);

        // A share made before this release (no marker) shows nothing.
        $this->service($app, VehicleShareRepository::class)
            ->insert($vehicle->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $partner = $this->browserFor($app, 'partner');
        self::assertStringNotContainsString('Got it', self::body($partner->get('/vehicles/' . $vehicle->id)));
        self::assertSame([], $pending->ids($member->id));

        // A new share does, until Got it.
        $sharing->remove($vehicle, $member->id);
        self::assertNull($sharing->add($vehicle, 'partner', ShareLevel::Log, false, false));
        self::assertSame([$vehicle->id], $pending->ids($member->id));
        $page = self::body($partner->get('/vehicles/' . $vehicle->id));
        self::assertStringContainsString('Pat Owner shared Volkswagen Golf with you.', $page);
        self::assertStringContainsString('You can', $page);
        self::assertStringContainsString('You can’t', $page);
        self::assertStringContainsString('Got it', $page);

        $seen = $partner->post('/vehicles/' . $vehicle->id . '/sharing/me/seen', []);
        self::assertSame(303, $seen->getStatusCode());
        self::assertSame([], $pending->ids($member->id));
        self::assertStringNotContainsString('Got it', self::body($partner->get('/vehicles/' . $vehicle->id)));

        // Removing a share forgets a marker that was never answered.
        $sharing->remove($vehicle, $member->id);
        $sharing->add($vehicle, 'partner', ShareLevel::View, false, false);
        self::assertSame([$vehicle->id], $pending->ids($member->id));
        $sharing->remove($vehicle, $member->id);
        self::assertSame([], $pending->ids($member->id));
    }

    /**
     * First-run setup through the form, as "owner" (a new install).
     *
     * @param App<ContainerInterface> $app
     */
    private function runSetup(App $app): TestBrowser
    {
        $this->resetDatabase($app);
        $browser = new TestBrowser($app);
        $browser->get('/setup');
        $response = $browser->post('/setup', [
            'username' => 'owner',
            'display_name' => 'Pat Owner',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'units' => 'metric',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $browser;
    }
}
