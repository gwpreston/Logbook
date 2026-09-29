<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The History tab, the fleet history and the print view (spec.md §7.16),
 * end to end. The owner uses UK units and GBP in Europe/London; "today" is
 * 27 Sep 2026.
 */
final class HistoryTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    public function testTheHistoryTabTellsTheVehiclesStory(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app);
        $this->fillUp($app, $golf, '2026-09-02T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '45', '70.00');
        $this->fillUp($app, $golf, '2026-09-17T08:00:00Z', '11000', '83.4', '154.10');
        $this->maintenance($app, $golf, '2026-09-20', 'Annual service', '189.50', '11100');
        $this->fillUp($app, $golf, '2026-09-24T08:00:00Z', '11400', '30', '45.00');
        $this->document($app, $golf, ComplianceType::Inspection, '2026-08-15', '2027-08-14', '54.85');
        $this->expense($app, $golf, '2025-06-01', '4.50', note: 'Car park');
        $base = '/vehicles/' . $golf->id;

        $html = self::body($browser->get($base . '/history'));
        // Second tab, current.
        $tab = '<a class="tabs__link" href="' . $base . '/history" aria-current="page">';
        self::assertMatchesRegularExpression('#tabs__link" href="' . $base . '">.*?</a>\s*' . $tab . '#s', $html);
        self::assertStringContainsString('href="' . $base . '/history/print"', $html);
        // The newest item's year.
        self::assertStringContainsString('<h2 class="history-years__year" id="history-year">2026</h2>', $html);
        self::assertStringContainsString('>September 2026</h3>', $html);
        self::assertStringContainsString('>August 2026</h3>', $html);
        self::assertStringContainsString('<time datetime="2026-09-20">', $html);

        // Three back-to-back fill-ups fold; the one after the service stands alone.
        self::assertSame(1, substr_count($html, '<details class="history-run">'));
        self::assertStringContainsString('3 fill-ups', $html);
        self::assertStringContainsString('£284.10', $html, 'the run\'s total');
        self::assertStringContainsString('168.4 L', $html, 'and the volume, in the owner\'s unit');
        self::assertStringContainsString('Older (2025)', $html);

        // Rows open their edit form in the modal and come back here.
        $return = preg_quote(urlencode($base . '/history'), '#');
        $edit = '#href="' . $base . '/maintenance/\d+/edit\?return=' . $return . '" data-modal#';
        self::assertMatchesRegularExpression($edit, $html);

        $older = self::body($browser->get($base . '/history?year=2025'));
        self::assertStringContainsString('Car park', $older);
        self::assertStringContainsString('Newer (2026)', $older);
    }

    public function testMilestonesBookendTheList(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app, purchased: '2021-05-01', price: '12500');
        $this->expense($app, $golf, '2021-05-01', '5.00');

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/history?year=2021'));
        self::assertStringContainsString('Bought for £12,500.00', $html);
        self::assertLessThan(strpos($html, 'Bought for'), strpos($html, 'Parking'), 'the day\'s entry sits above Bought');
        // A milestone opens the vehicle.
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/edit?return=', $html);
        self::assertStringNotContainsString('list__amount tabular">£12,500', $html, 'never in the amount column');

        // Milestones show under Everything only; empty years in range have a page.
        $service = self::body($browser->get('/vehicles/' . $golf->id . '/history?kind=service'));
        self::assertStringContainsString('Nothing logged yet', $service);
        $between = self::body($browser->get('/vehicles/' . $golf->id . '/history?year=2020'));
        self::assertStringContainsString('Nothing logged in 2020.', $between);
        $registered = self::body($browser->get('/vehicles/' . $golf->id . '/history?year=2019'));
        self::assertStringContainsString('First registered', $registered);
        self::assertStringContainsString('<h2 class="history-years__year" id="history-year">2021</h2>', self::body(
            $browser->get('/vehicles/' . $golf->id . '/history?year=1850'),
        ), 'out of range: the default year');
    }

    public function testKindChipsWorkWithoutJsAndRespectSwitchedOffModules(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app);
        $this->fillUp($app, $golf, '2026-09-02T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '45', '70.00');
        $this->reading($app, $golf, '10600', '2026-09-12T08:00:00Z');
        $path = '/vehicles/' . $golf->id . '/history';

        $fuel = self::body($browser->get($path . '?kind=fuel'));
        self::assertStringContainsString('href="' . $path . '?kind=fuel" aria-current="page"', $fuel);
        self::assertStringNotContainsString('<details', $fuel, 'nothing folds under Fuel');
        self::assertStringNotContainsString('Odometer reading', $fuel);
        self::assertStringContainsString('Odometer reading', self::body($browser->get($path . '?kind=mileage')));
        $unknown = self::body($browser->get($path . '?kind=bananas'));
        self::assertStringContainsString('href="' . $path . '" aria-current="page"', $unknown, 'falls back to Everything');

        $this->service($app, FeatureToggles::class)->save([Feature::Maintenance, Feature::Compliance, Feature::Reminders]);
        $off = self::body($browser->get($path . '?kind=fuel'));
        self::assertStringNotContainsString('href="' . $path . '?kind=fuel"', $off, 'the chip is gone');
        self::assertStringNotContainsString('Fill-up', $off, 'and so are the fill-ups');
        self::assertStringContainsString('Odometer reading', $off);
    }

    public function testNothingLoggedYetAndArchivedVehicles(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app); // no dates of its own, so no milestones

        $empty = self::body($browser->get('/vehicles/' . $golf->id . '/history'));
        self::assertStringContainsString('Nothing logged yet', $empty);
        self::assertStringContainsString('href="/log/new" data-modal', $empty);

        $this->maintenance($app, $golf, '2026-09-01', 'Annual service', '100');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);
        $archived = self::body($browser->get('/vehicles/' . $golf->id . '/history'));
        self::assertStringContainsString('Annual service', $archived);
        $print = $browser->get('/vehicles/' . $golf->id . '/history/print');
        self::assertSame(200, $print->getStatusCode());
        self::assertStringContainsString('Annual service', self::body($print));
    }

    public function testSavingAnEditReturnsToTheHistoryPageOnlyForLocalPaths(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app);
        $this->fillUp($app, $golf, '2025-09-02T08:00:00Z', '10000', '40', '60.00');
        $entry = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id)[0];
        $edit = '/vehicles/' . $golf->id . '/fuel/' . $entry->id . '/edit';
        $back = '/vehicles/' . $golf->id . '/history?year=2025&kind=fuel';
        $fields = [
            'filled_at' => '2025-09-02T09:00',
            'odometer' => '10000',
            'fuel' => 'petrol',
            'volume' => '40',
            'price' => '',
            'total' => '61',
        ];

        $form = self::body($browser->get($edit . '?return=' . urlencode($back)));
        self::assertStringContainsString('<input type="hidden" name="return" value="' . htmlspecialchars($back) . '">', $form);
        self::assertSame($back, $browser->post($edit, $fields + ['return' => $back])->getHeaderLine('Location'));

        $modal = $browser->post($edit, $fields + ['return' => $back], headers: ['X-Logbook-Modal' => '1']);
        self::assertSame($back, $modal->getHeaderLine('X-Logbook-Location'));

        // A 422 keeps it.
        $invalid = $browser->post($edit, ['odometer' => ''] + $fields + ['return' => $back]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('name="return" value="' . htmlspecialchars($back) . '"', self::body($invalid));

        foreach (['https://evil.example/', '//evil.example/', '/\\evil.example'] as $evil) {
            $saved = $browser->post($edit, $fields + ['return' => $evil]);
            self::assertSame('/vehicles/' . $golf->id . '/fuel', $saved->getHeaderLine('Location'), $evil);
        }
        $foreign = self::body($browser->get($edit . '?return=' . urlencode('https://evil.example/')));
        self::assertStringNotContainsString('name="return"', $foreign);
    }

    public function testTheFleetHistoryWithVehicleAndKindChips(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app);
        $bike = $this->vehicle($app, 'Triumph', 'Street Triple', type: VehicleType::Bike);
        $this->fillUp($app, $golf, '2026-09-02T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '45', '70.00');
        $this->fillUp($app, $bike, '2026-09-12T08:00:00Z', '3000', '12', '20.00');
        $this->fillUp($app, $bike, '2026-09-14T08:00:00Z', '3200', '12', '21.00');
        $this->expense($app, $bike, '2026-09-20', '3.00');

        $html = self::body($browser->get('/history'));
        self::assertStringContainsString('Vehicle history', $html);
        self::assertStringContainsString('href="/history?vehicle=' . $bike->id . '"', $html);
        self::assertSame(2, substr_count($html, '<details class="history-run">'), 'runs fold per vehicle');
        self::assertStringContainsString(' · Triumph Street Triple', $html, 'rows name their vehicle');

        $bikeOnly = self::body($browser->get('/history?vehicle=' . $bike->id . '&kind=expenses'));
        self::assertStringContainsString('Parking', $bikeOnly);
        self::assertStringNotContainsString('Golf', explode('<section class="card history"', $bikeOnly)[1] ?? '');
        $chosen = 'href="/history?vehicle=' . $bike->id . '&amp;kind=expenses" aria-current="page"';
        self::assertStringContainsString($chosen, $bikeOnly);

        // An archived or unknown vehicle means every vehicle.
        $this->service($app, VehicleService::class)->archive($this->owner($app), $bike);
        $archived = self::body($browser->get('/history?vehicle=' . $bike->id));
        self::assertStringNotContainsString('Street Triple', $archived, 'archived vehicles are on their own tab');
        self::assertStringContainsString('Fill-up', self::body($browser->get('/history?vehicle=999999')));
    }

    public function testTheDashboardAndOverviewLeadToTheHistory(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app);
        $this->vehicle($app, 'Triumph', 'Street Triple', type: VehicleType::Bike);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06'] as $day) {
            $this->expense($app, $golf, $day, '1.00', note: 'Day ' . $day);
        }

        self::assertStringContainsString('href="/history">View all</a>', self::body($browser->get('/')));
        self::assertStringContainsString(
            'href="/history?vehicle=' . $golf->id . '">View all</a>',
            self::body($browser->get('/?vehicle=' . $golf->id)),
            'keeps the chosen vehicle',
        );

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('Recent history', $overview);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/history">Full history →</a>', $overview);
        self::assertStringContainsString('Day 2026-09-06', $overview);
        self::assertStringContainsString('Day 2026-09-02', $overview);
        self::assertStringNotContainsString('Day 2026-09-01', $overview, 'the latest five');
    }

    public function testThePrintViewIsAServiceHistoryWithOrWithoutCosts(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->golf($app, purchased: '2021-05-01', price: '12500');
        $this->fillUp($app, $golf, '2026-09-02T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10500', '45', '70.00');
        $this->maintenance($app, $golf, '2024-03-01', 'Timing belt', '420.00', '9000');
        $this->maintenance($app, $golf, '2026-09-20', 'Annual service', '189.50');
        $path = '/vehicles/' . $golf->id . '/history/print';

        $default = self::body($browser->get($path));
        self::assertStringContainsString('Service history', $default);
        self::assertStringContainsString('GO19 ABC', $default);
        self::assertStringContainsString('First registered', $default);
        self::assertStringContainsString('7 yrs 6 mo', $default, 'with its age');
        self::assertStringContainsString('27 Sept 2026', $default, 'the date printed');
        self::assertStringContainsString('Timing belt', $default, 'every year on one page');
        self::assertStringContainsString('Annual service', $default);
        // Fuel is left out by default.
        self::assertStringNotContainsString('Fill-up', explode('history-print__sheet', $default)[1] ?? '');
        self::assertStringNotContainsString('<details', $default, 'nothing folds');
        self::assertStringContainsString('£420.00', $default);
        self::assertStringContainsString('Bought for £12,500.00', $default);
        // Print needs JS.
        self::assertStringContainsString('<button type="button" class="btn btn--primary" data-print hidden>', $default);

        $withFuel = self::body($browser->get($path . '?options=1&kinds[]=service&kinds[]=fuel'));
        self::assertSame(2, substr_count(explode('history-print__sheet', $withFuel)[1] ?? '', 'list__title">Fill-up'));
        self::assertStringNotContainsString('£420.00', $withFuel, 'costs unticked');
        self::assertStringNotContainsString('£12,500', $withFuel, 'and so the prices');
        self::assertStringContainsString('>Bought<', $withFuel);
        self::assertStringContainsString('Timing belt', $withFuel);
    }

    public function testHistoryAtASubpathSurvivesAHardRefresh(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/logbook/login');
        $browser->post('/logbook/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $golf = $this->golf($app);
        $this->maintenance($app, $golf, '2026-09-01', 'Annual service', '100');

        $history = '/vehicles/' . $golf->id . '/history?kind=service';
        // With the prefix, and with it stripped by the proxy.
        foreach (['/logbook' . $history, $history] as $path) {
            $page = $browser->get($path);
            self::assertSame(200, $page->getStatusCode(), $path);
            self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/history/print"', self::body($page));
            self::assertStringContainsString('?return=' . urlencode('/logbook' . $history), self::body($page));
        }
        self::assertSame(200, $browser->get('/logbook/history')->getStatusCode());
        self::assertSame(200, $browser->get('/logbook/vehicles/' . $golf->id . '/history/print')->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app, ?string $purchased = null, ?string $price = null): Vehicle
    {
        $golf = $this->vehicle($app);

        return $this->service($app, VehicleService::class)->update($this->owner($app), $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            registration: 'GO19 ABC',
            purchaseDate: $purchased === null ? null : LocalTime::parseDate($purchased),
            purchasePrice: $price,
            firstRegisteredOn: LocalTime::parseDate('2019-03-14'),
        ));
    }
}
