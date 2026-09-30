<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Settings → Modules (spec.md §7.10): a switched-off module's pages answer
 * 404, and it leaves the navigation, vehicle tabs, dashboard and reminders;
 * switching it back on restores everything, data included.
 */
final class FeatureToggleTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testSettingsPageSavesEveryModule(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $html = self::body($browser->get('/settings/modules'));
        foreach (Feature::cases() as $feature) {
            self::assertStringContainsString('name="' . $feature->value . '" value="1" checked', $html);
        }
        self::assertStringContainsString('href="/settings/modules"', self::body($browser->get('/settings')));

        $response = $browser->post('/settings/modules', ['maintenance' => '1', 'reminders' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            [
                'fuel' => false,
                'maintenance' => true,
                'compliance' => false,
                'reminders' => true,
                'reports' => false,
                'tyres' => false,
            ],
            $this->service($app, FeatureToggles::class)->all(),
        );
        $html = self::body($browser->follow($response));
        self::assertStringContainsString('Your modules were saved.', $html);
        self::assertStringContainsString('name="fuel" value="1" aria-describedby', $html, 'unticked now');
    }

    public function testASwitchedOffModuleIsAbsentAndComesBack(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = $golf->id;

        $modulePages = [
            'fuel' => [
                '/fuel/new',
                "/vehicles/$id/fuel",
                "/vehicles/$id/fuel/new",
                "/vehicles/$id/export/fuel.csv",
                "/vehicles/$id/import/fuel",
            ],
            'maintenance' => [
                "/vehicles/$id/maintenance",
                "/vehicles/$id/maintenance/new",
                "/vehicles/$id/maintenance/schedules/new",
                "/vehicles/$id/export/maintenance.csv",
                "/vehicles/$id/import/maintenance",
            ],
            'compliance' => [
                "/vehicles/$id/documents",
                "/vehicles/$id/documents/new",
                "/vehicles/$id/export/documents.csv",
                "/vehicles/$id/import/documents",
            ],
            'reminders' => ['/reminders', '/reminders/new'],
            'reports' => ['/reports', '/reports/export.csv'],
        ];
        foreach ($modulePages as $pages) {
            foreach ($pages as $page) {
                self::assertContains($browser->get($page)->getStatusCode(), [200, 303], $page . ' before');
            }
        }

        $this->service($app, FeatureToggles::class)->save([]);

        foreach ($modulePages as $pages) {
            foreach ($pages as $page) {
                self::assertSame(404, $browser->get($page)->getStatusCode(), $page . ' while off');
            }
        }
        // Core pages stay, and so do the always-on lists' export and import.
        $core = ['/', '/garage', "/vehicles/$id", "/vehicles/$id/odometer", "/vehicles/$id/expenses", '/settings/reminders'];
        foreach ($core as $page) {
            self::assertSame(200, $browser->get($page)->getStatusCode(), $page);
        }
        self::assertSame(200, $browser->get("/vehicles/$id/export/odometer.csv")->getStatusCode());
        self::assertSame(200, $browser->get("/vehicles/$id/import/expenses")->getStatusCode());

        // Gone from the navigation, the vehicle tabs and the overview.
        $shell = self::body($browser->get("/vehicles/$id"));
        $gone = [
            'href="/fuel/new"',
            'href="/reminders"',
            'href="/reports"',
            "href=\"/vehicles/$id/fuel\"",
            "href=\"/vehicles/$id/maintenance\"",
            "href=\"/vehicles/$id/documents\"",
        ];
        foreach ($gone as $link) {
            self::assertStringNotContainsString($link, $shell);
        }
        self::assertStringContainsString("href=\"/vehicles/$id/odometer\"", $shell);
        self::assertStringContainsString("href=\"/vehicles/$id/expenses\"", $shell);

        // A signed-out visitor is sent to sign in, not told what is off.
        $visitor = new TestBrowser($app);
        self::assertSame(303, $visitor->get('/reports')->getStatusCode());

        $this->service($app, FeatureToggles::class)->save(Feature::cases());
        $shell = self::body($browser->get("/vehicles/$id"));
        foreach (['href="/log/new"', 'href="/reminders"', 'href="/reports"', "href=\"/vehicles/$id/fuel\""] as $link) {
            self::assertStringContainsString($link, $shell);
        }
        self::assertSame(200, $browser->get('/reports')->getStatusCode());
    }

    public function testEnvironmentDefaultAppliesUntilSaved(): void
    {
        $app = $this->createApp(['FEATURES_REPORTS' => 'false']);
        $browser = $this->signedIn($app);

        self::assertSame(404, $browser->get('/reports')->getStatusCode());
        $modules = self::body($browser->get('/settings/modules'));
        self::assertStringContainsString('name="reports" value="1" aria-describedby', $modules);

        $browser->post('/settings/modules', ['reports' => '1', 'fuel' => '1']);
        self::assertSame(200, $browser->get('/reports')->getStatusCode(), 'the saved setting wins over the variable');
    }

    public function testASwitchedOffModulesRemindersAreKeptButNeitherListedNorSent(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->schedule($app, $golf, 'Annual service', '2025-10-01');
        $this->document($app, $golf, '2026-10-05');
        $owner = $this->owner($app);

        self::assertCount(2, $this->service($app, ReminderService::class)->overview($owner)->open);
        $schedule = $this->reminderFrom($app, ReminderSource::Schedule);
        $this->service($app, ReminderService::class)->dismiss($schedule);

        $this->service($app, FeatureToggles::class)->save([Feature::Fuel, Feature::Compliance, Feature::Reminders]);
        $html = self::body($browser->get('/reminders'));
        self::assertStringNotContainsString('Annual service', $html);
        self::assertStringContainsString('Insurance', $html);
        self::assertCount(2, $this->reminders($app), 'the schedule reminder is kept, untouched');

        // Only the document goes out.
        self::assertSame(1, $this->service($app, ScheduledTasks::class)->run()->remindersSent);
        self::assertCount(1, $this->mail->sent);
        self::assertStringContainsString('Insurance', (string) $this->mail->sent[0]->getSubject());

        // Back on: the schedule's reminder is there with its dismissal.
        $this->service($app, FeatureToggles::class)->save(Feature::cases());
        $this->service($app, ReminderService::class)->overview($owner);
        self::assertSame(ReminderStatus::Dismissed, $this->reminderFrom($app, ReminderSource::Schedule)->status);
        self::assertSame($schedule->id, $this->reminderFrom($app, ReminderSource::Schedule)->id);
    }

    public function testRemindersOffStopsNotificationsAndTheCalendarFeed(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-05');
        $owner = $this->owner($app);
        $feeds = $this->service($app, CalendarFeed::class);
        $feedPath = (string) parse_url($feeds->urls($feeds->issueToken($owner))['https'], PHP_URL_PATH);
        self::assertSame(200, $browser->get($feedPath)->getStatusCode());

        $this->service($app, FeatureToggles::class)
            ->save([Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reports]);

        self::assertSame(404, (new TestBrowser($app))->get($feedPath)->getStatusCode());
        self::assertSame(0, $this->service($app, ScheduledTasks::class)->run()->remindersSent);
        self::assertSame([], $this->mail->sent);

        // Settings → Reminders keeps the lead times; the notification choices stay as saved.
        $html = self::body($browser->get('/settings/reminders'));
        self::assertStringContainsString('name="schedule_days"', $html);
        self::assertStringNotContainsString('name="channels[]"', $html);
        $before = $this->service($app, ReminderSettingsStore::class)->notificationPreferences($owner->id);
        $response = $browser->post('/settings/reminders', [
            'schedule_days' => '10',
            'schedule_distance' => '500',
            'document_days' => '20',
            'manual_days' => '3',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertEquals($before, $this->service($app, ReminderSettingsStore::class)->notificationPreferences($owner->id));
        self::assertSame(20, $this->service($app, ReminderSettingsStore::class)->reminderPreferences($owner->id)->documentDays);
    }

    public function testDashboardAndLogChooserLeaveOutSwitchedOffModules(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        $html = self::body($browser->get('/'));
        self::assertStringContainsString('id="widget-recent_fuel"', $html);
        self::assertStringContainsString('bottom-nav__link--fab', $html);

        $this->service($app, FeatureToggles::class)
            ->save([Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports]);
        $html = self::body($browser->get('/'));
        self::assertStringNotContainsString('id="widget-recent_fuel"', $html);
        self::assertStringNotContainsString('id="widget-efficiency"', $html);
        self::assertStringNotContainsString('Log fill-up', $html);
        // "+ Log entry" stays (readings and expenses are core); its chooser drops fuel.
        self::assertStringContainsString('bottom-nav__link--fab" href="/log/new"', $html);
        $chooser = self::body($browser->get('/log/new'));
        self::assertStringNotContainsString('href="/fuel/new"', $chooser);
        self::assertStringContainsString('href="/log/new/odometer"', $chooser);
        self::assertStringContainsString('href="/log/new/schedule"', $chooser);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function reminderFrom(App $app, ReminderSource $source): \Logbook\Domain\Reminder\Reminder
    {
        $vehicles = $this->ownedVehicles($app, $this->owner($app)->id);
        $vehicleIds = array_map(static fn (Vehicle $vehicle): int => $vehicle->id, $vehicles);
        foreach ($this->service($app, ReminderRepository::class)->listForVehicles($vehicleIds) as $reminder) {
            if ($reminder->source === $source) {
                return $reminder;
            }
        }
        self::fail('no reminder from ' . $source->value);
    }
}
