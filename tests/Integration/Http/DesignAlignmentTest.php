<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\Accent;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Phase 7 (spec.md §5, §7.1–7.3, §7.8, §8): modal forms, the Log entry
 * chooser, the sidebar's badge and vehicles, the dashboard filter and pinned
 * card, the mileage and activity widgets, the expenses tab's 12-month chart,
 * the accent colour and the version. "Today" is 27 Sep 2026 (Europe/London).
 */
final class DesignAlignmentTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const array MODAL = ['X-Logbook-Modal' => '1'];
    /** A valid 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map(static fn (string $file) => is_file($file) && unlink($file), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testAModalRequestGetsTheSameFormAloneAndARedirectBecomes204(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $path = '/vehicles/' . $golf->id . '/expenses/new';

        $page = self::body($browser->get($path));
        self::assertStringContainsString('<html', $page, 'the page is a whole page');
        self::assertStringNotContainsString('data-modal-fragment', $page);

        $fragment = $browser->get($path, self::MODAL);
        $html = self::body($fragment);
        self::assertSame(200, $fragment->getStatusCode());
        self::assertStringNotContainsString('<html', $html, 'the modal gets the form alone');
        self::assertStringContainsString('data-modal-title="Add an expense"', $html);
        self::assertStringContainsString('action="' . $path . '"', $html);
        self::assertStringContainsString('name="csrf_value"', $html);
        self::assertStringContainsString('data-modal-cancel', $html);

        // A validation error comes back as the form, with the error.
        $invalid = $browser->post($path, ['category' => 'parking', 'spent_on' => 'not a date'], [], true, self::MODAL);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('data-modal-fragment', self::body($invalid));
        self::assertStringContainsString('field__error', self::body($invalid));

        // Success: 204 with the redirect's target; the flash waits for that page.
        $fields = ['category' => 'parking', 'spent_on' => '2026-09-20', 'amount' => '4.50'];
        $saved = $browser->post($path, $fields, [], true, self::MODAL);
        self::assertSame(204, $saved->getStatusCode());
        self::assertSame('/vehicles/' . $golf->id . '/expenses', $saved->getHeaderLine('X-Logbook-Location'));
        self::assertFalse($saved->hasHeader('Location'));
        self::assertStringContainsString('Expense saved.', self::body($browser->get('/vehicles/' . $golf->id . '/expenses')));
    }

    public function testFilesUploadFromTheModal(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new', self::MODAL);

        $bike = ['type' => 'bike', 'make' => 'Triumph', 'model' => 'Street Triple R', 'fuel_type' => 'petrol'];
        $photo = ['photo' => $this->upload(base64_decode(self::PNG))];
        $response = $browser->post('/vehicles/new', $bike, $photo, true, self::MODAL);

        self::assertSame(204, $response->getStatusCode());
        $vehicles = $this->service($app, VehicleService::class)->listFleet($this->owner($app));
        self::assertCount(1, $vehicles);
        self::assertSame('image/png', $vehicles[0]->photoMime);
    }

    public function testEveryEntryFormHasAModalBody(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $id = $this->vehicle($app)->id;

        $forms = [
            '/vehicles/new', "/vehicles/$id/edit", "/vehicles/$id/fuel/new", "/vehicles/$id/odometer/new",
            "/vehicles/$id/maintenance/new", "/vehicles/$id/maintenance/schedules/new",
            "/vehicles/$id/documents/new", "/vehicles/$id/expenses/new", '/log/new',
        ];
        foreach ($forms as $form) {
            $html = self::body($browser->get($form, self::MODAL));
            self::assertStringContainsString('data-modal-fragment', $html, $form);
            self::assertStringNotContainsString('<html', $html, $form);
        }
        // Pages that are not forms are never cut down.
        self::assertStringContainsString('<html', self::body($browser->get('/garage', self::MODAL)));
    }

    public function testLogEntryChooserAndVehiclePicker(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $html = self::body($browser->get('/log/new'));
        foreach (['Fill-up', 'Odometer reading', 'Service record', 'Expense', 'Document', 'Service interval'] as $choice) {
            self::assertStringContainsString($choice, $html);
        }
        self::assertStringContainsString('href="/fuel/new"', $html);

        // No vehicle yet: add one first.
        self::assertSame('/vehicles/new', $browser->get('/log/new/expense')->getHeaderLine('Location'));

        $golf = $this->vehicle($app);
        self::assertSame(
            '/vehicles/' . $golf->id . '/expenses/new',
            $browser->get('/log/new/expense')->getHeaderLine('Location'),
            'one vehicle: straight to its form',
        );

        $bike = $this->vehicle($app, 'Triumph', 'Street Triple', type: VehicleType::Bike);
        $sold = $this->vehicle($app, 'Ford', 'Mondeo');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $sold);
        $picker = self::body($browser->get('/log/new/schedule'));
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/maintenance/schedules/new"', $picker);
        self::assertStringContainsString('href="/vehicles/' . $bike->id . '/maintenance/schedules/new"', $picker);
        self::assertStringNotContainsString('Mondeo', $picker);

        self::assertSame(404, $browser->get('/log/new/bogus')->getStatusCode());
    }

    public function testSidebarBadgeAndVehicleDots(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Triumph', 'Street Triple', type: VehicleType::Bike);
        $sold = $this->vehicle($app, 'Ford', 'Mondeo');
        $this->remind($app, $golf->id, 'Road tax', '2026-09-20');   // overdue
        $this->remind($app, $golf->id, 'Wash', '2026-09-29');       // due soon
        $this->remind($app, $bike->id, 'Chain', '2026-12-01');      // upcoming
        $this->remind($app, $sold->id, 'Old policy', '2026-09-01'); // archived: never counted
        $this->service($app, VehicleService::class)->archive($this->owner($app), $sold);

        $html = self::body($browser->get('/garage'));

        self::assertStringContainsString('<span class="nav-link__badge" aria-hidden="true">2</span>', $html);
        self::assertStringContainsString('2 reminders need attention', $html);
        self::assertStringContainsString('status-dot--overdue" title="1 overdue, 1 due soon"', $html);
        self::assertStringContainsString('status-dot--ok" title="All up to date"', $html);
        self::assertStringNotContainsString('Mondeo', $html, 'archived vehicles are not listed');
        // The garage card badge counts the same.
        self::assertMatchesRegularExpression(
            '~vehicle-card__flags">\s*<span class="due-badge due-badge--overdue"[^>]*>2 due<~',
            $html,
        );

        // Reminders switched off: no badge, no dots.
        $this->service($app, FeatureToggles::class)->save([]);
        $off = self::body($browser->get('/garage'));
        self::assertStringNotContainsString('nav-link__badge', $off);
        self::assertStringNotContainsString('status-dot', $off);
    }

    public function testDashboardFilterPinsTheSelectedVehicle(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Triumph', 'Street Triple', type: VehicleType::Bike);
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '10000', '40', '58.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10800', '45.461', '66.64');
        $this->expense($app, $bike, '2026-09-20', '12.00');
        $this->remind($app, $golf->id, 'Road tax', '2026-10-01');

        $fleet = self::body($browser->get('/'));
        self::assertStringContainsString('href="/?vehicle=' . $golf->id . '"', $fleet);
        self::assertStringContainsString('<a class="chip vehicle-filter__chip" href="/" aria-current="page">', $fleet);
        self::assertStringNotContainsString('data-pinned-vehicle', $fleet);
        self::assertStringContainsString('id="widget-fleet"', $fleet);

        $one = self::body($browser->get('/?vehicle=' . $golf->id));
        self::assertStringContainsString('data-pinned-vehicle', $one);
        self::assertStringNotContainsString('id="widget-fleet"', $one, 'your vehicles makes way for the card');
        self::assertStringContainsString('49.7 mpg', $one, '12-month economy in the owner\'s unit');
        self::assertStringContainsString('in 4 days', $one);
        self::assertStringContainsString('Road tax', $one);
        // Every widget covers the selected vehicle only: the bike's expense is not recent activity.
        self::assertStringNotContainsString('Street Triple · ', $one);

        // The pinned card is never part of customise mode.
        $customise = self::body($browser->get('/?vehicle=' . $golf->id . '&customise=1'));
        self::assertStringNotContainsString('data-pinned-vehicle', $customise);

        // Unknown or archived: the fleet.
        self::assertStringNotContainsString('data-pinned-vehicle', self::body($browser->get('/?vehicle=99999')));
        $this->service($app, VehicleService::class)->archive($this->owner($app), $bike);
        $archived = self::body($browser->get('/?vehicle=' . $bike->id));
        self::assertStringNotContainsString('data-pinned-vehicle', $archived);
        self::assertStringNotContainsString('vehicle-filter', $archived, 'one active vehicle: no filter');
    }

    public function testMileageAndRecentActivityWidgets(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $golf, '2000', '2026-08-31T12:00:00Z');
        $this->reading($app, $golf, '2500', '2026-09-20T12:00:00Z');
        $this->fillUp($app, $golf, '2026-09-25T08:00:00Z', '2600', '40', '58.00');
        $this->expense($app, $golf, '2026-09-26', '3.20');

        $html = self::body($browser->get('/'));

        // UK owner, miles: this month 600 km, this year 1,600 km.
        $mileage = explode('id="widget-recent_activity"', explode('id="widget-mileage"', $html)[1] ?? '')[0];
        self::assertStringContainsString('373 mi', $mileage);
        self::assertStringContainsString('994 mi', $mileage);
        self::assertStringContainsString('data-chart=', $mileage, 'the last 12 months as bars');

        $activity = explode('id="widget-recent_activity"', $html)[1] ?? '';
        $expense = strpos($activity, 'Parking');
        $fill = strpos($activity, 'Fill-up');
        self::assertNotFalse($expense);
        self::assertNotFalse($fill);
        self::assertLessThan($fill, $expense, 'newest first');
        self::assertSame(3, substr_count($activity, 'Odometer reading'), 'the fill-up\'s own reading is left out');
    }

    public function testExpensesChartAlwaysCoversTheLastTwelveMonths(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->expense($app, $golf, '2026-04-15', '9.99');

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/expenses?range=month'));
        [$page, $chart] = array_pad(explode('data-expenses-monthly', $html, 2), 2, '');

        self::assertStringNotContainsString('£9.99', $page, 'not in this month\'s figures');
        self::assertStringContainsString('£9.99', explode('id="costs-heading"', $chart)[0], 'but in the 12-month chart');
    }

    public function testAccentIsSavedPerUserAndRenderedOnTheShell(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        self::assertStringContainsString('data-accent="blue"', self::body($browser->get('/settings')));

        $prefs = [
            'display_name' => 'Pat Owner', 'theme' => 'system', 'accent' => 'teal', 'distance_unit' => 'mi',
            'volume_unit' => 'l', 'consumption_unit' => 'mpg_uk', 'currency' => 'GBP', 'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ];
        $browser->post('/settings/preferences', $prefs);

        self::assertStringContainsString('data-accent="teal"', self::body($browser->get('/')));
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertSame(Accent::Teal, $owner?->preferences->accent);
        self::assertSame(422, $browser->post('/settings/preferences', ['accent' => 'pink'] + $prefs)->getStatusCode());
        // Signed out: the default.
        self::assertStringContainsString('data-accent="blue"', self::body($this->get($app, '/login')));
    }

    public function testSidebarOrderAndTheFuelStationsLabel(): void
    {
        $browser = $this->signedIn($this->createApp());
        $home = self::body($browser->get('/'));
        $sidebar = substr($home, (int) strpos($home, '<aside class="sidebar">'), (int) strpos($home, '</nav>'));

        preg_match_all('~<span class="nav-link__label">([^<]+)</span>~', $sidebar, $labels);
        self::assertSame(['Dashboard', 'Garage', 'Reminders', 'Reports', 'Fuel stations', 'Settings'], $labels[1]);
        self::assertStringContainsString('<title>Fuel stations · Logbook</title>', self::body($browser->get('/stations')));

        $browser->get('/settings/modules');
        $browser->post('/settings/modules', ['fuel' => '1', 'reminders' => '1', 'reports' => '1']);
        self::assertStringNotContainsString('Fuel stations', self::body($browser->get('/')), 'module off: absent');
    }

    public function testVersionIsShownInTheSidebarAndOnSettings(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $text = 'Logbook v' . Kernel::version();

        $sidebar = '<p class="sidebar__version" data-app-version>' . $text . '</p>';
        self::assertStringContainsString($sidebar, self::body($browser->get('/')));
        self::assertStringContainsString('<dd data-app-version>' . $text . '</dd>', self::body($browser->get('/settings')));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function remind(App $app, int $vehicleId, string $title, string $due): void
    {
        $this->service($app, ReminderService::class)->createManual(
            $this->owner($app),
            new ManualReminderData($vehicleId, $title, new DateTimeImmutable($due, new DateTimeZone('UTC')), 7),
        );
    }

    private function upload(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'photo.png', 'image/png', strlen($contents), UPLOAD_ERR_OK);
    }
}
