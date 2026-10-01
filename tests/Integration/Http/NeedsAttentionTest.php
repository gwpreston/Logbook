<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Needs attention end to end (spec.md §7.24, docs/phases/phase-24.md): the
 * overview card, its order and links, each kind appearing exactly when its
 * source says so and leaving once fixed, dismissals, hiding by
 * fingerprint, the thresholds, module toggles, archived vehicles and the
 * access matrix. The owner uses UK units (miles) in London; "today" is
 * 1 Oct 2026.
 */
final class NeedsAttentionTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T10:00:00Z';
    private const string LONDON = 'Europe/London';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private int $day = 0;
    private int $km = 10000;

    public function testAnExpiredMotABackwardsReadingAndTwoUnusualFillUpsAreThreeItemsMotFirst(): void
    {
        $this->start();
        $golf = $this->troubled();

        $html = self::body($this->browser->get('/vehicles/' . $golf->id));
        $items = $this->items($html);

        self::assertCount(3, $items, 'one item per problem: the two fill-ups are one');
        self::assertStringContainsString('Renew Inspection (MOT)', $items[0]);
        self::assertStringContainsString('Expired 3 days ago', $items[0]);
        self::assertStringContainsString('is lower than the one before', $items[1]);
        self::assertStringContainsString('2 fill-ups look unusual', $items[2]);

        // Each with its label as text and its fix one tap away.
        self::assertStringContainsString('>Now</span>', $items[0]);
        self::assertStringContainsString('>Check</span>', $items[1]);
        self::assertStringContainsString('>Check</span>', $items[2]);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/documents/new?type=inspection"', $items[0]);
        self::assertStringContainsString('Dismiss', $items[0], 'reminders on: dismissed through its reminder');
        $reading = $this->backwards($golf);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/edit"', $items[1]);
        self::assertStringContainsString('Hide', $items[1]);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel?check=1"', $items[2]);
        self::assertStringNotContainsString('Hide', $items[2], 'fill-ups are confirmed with Looks right');

        // The card comes first and is never a score.
        self::assertLessThan(strpos($html, 'class="stats"'), strpos($html, 'id="attention-heading"'));
        self::assertDoesNotMatchRegularExpression('~\d+\s?% (healthy|health)|health score~i', $html);
    }

    public function testFixingEachOneRemovesItAndThenTheCardIsGone(): void
    {
        $this->start();
        $golf = $this->troubled();
        $overview = '/vehicles/' . $golf->id;

        // Renew the MOT: the expired certificate is replaced.
        $this->document($this->app, $golf, '2027-09-28', ComplianceType::Inspection, '2026-09-29');
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(2, $items);
        self::assertStringNotContainsString('MOT', implode('', $items));

        // Correct the backwards reading.
        $reading = $this->backwards($golf);
        $this->service($this->app, OdometerService::class)->update(
            $golf,
            $reading,
            new OdometerReadingData('19500', new DateTimeImmutable('2026-06-01T09:00:00Z')),
        );
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(1, $items);
        self::assertStringContainsString('fill-ups look unusual', $items[0]);

        // Looks right on each flagged fill-up, on the Fuel tab as before.
        $this->browser->get($overview . '/fuel?check=1');
        foreach ($this->flagged($golf) as $entry) {
            self::assertSame(303, $this->browser->post($overview . '/fuel/' . $entry->id . '/economy')->getStatusCode());
        }

        $html = self::body($this->browser->get($overview));
        self::assertStringNotContainsString('id="attention-heading"', $html, 'nothing wrong: no card at all');
    }

    public function testARightReadingCanBeHiddenAndComesBackWhenItOrItsNeighboursChange(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->reading($golf, '10000', '2026-05-01T09:00:00Z');
        $this->reading($golf, '9000', '2026-06-01T09:00:00Z'); // a new speedometer, really lower
        $this->reading($golf, '9500', '2026-07-01T09:00:00Z');
        $overview = '/vehicles/' . $golf->id;

        $html = self::body($this->browser->get($overview));
        self::assertCount(1, $this->items($html));

        // A changed state is never hidden: the posted fingerprint must match.
        $form = $this->hideForm($html);
        $hide = '/vehicles/' . $golf->id . '/attention/hide';
        $stale = $this->browser->post($hide, ['fingerprint' => str_repeat('0', 64)] + $form);
        self::assertSame(303, $stale->getStatusCode());
        $html = self::body($this->browser->follow($stale));
        self::assertStringContainsString('That has changed since the page loaded', $html);
        self::assertCount(1, $this->items($html));

        $hidden = $this->browser->post('/vehicles/' . $golf->id . '/attention/hide', $form + ['return' => $overview]);
        self::assertSame($overview, $hidden->getHeaderLine('Location'), 'back where it came from');
        $html = self::body($this->browser->follow($hidden));
        self::assertStringContainsString('Hidden. It comes back if that data changes.', $html);
        self::assertSame([], $this->items($html));

        // Hidden per user: a manager of the vehicle still sees it.
        $partner = $this->share($golf, ShareLevel::Manage);
        self::assertCount(1, $this->items(self::body($partner->get($overview))));

        // Editing the reading after it brings it back.
        $readings = $this->service($this->app, OdometerService::class)->history($golf)->readings;
        $this->service($this->app, OdometerService::class)->update(
            $golf,
            $readings[2],
            new OdometerReadingData('9600', $readings[2]->recordedAt),
        );
        $html = self::body($this->browser->get($overview));
        self::assertCount(1, $this->items($html), 'a neighbour changed');

        // Hidden again, then a reading added between them brings it back too.
        $this->browser->post('/vehicles/' . $golf->id . '/attention/hide', $this->hideForm($html));
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));
        $this->reading($golf, '9200', '2026-06-15T09:00:00Z');
        self::assertCount(1, $this->items(self::body($this->browser->get($overview))));
    }

    public function testOverdueWorkHonoursDismissalsAndWorksWithRemindersOff(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $service = $this->schedule($this->app, $golf, 'Annual service', '2025-09-01');
        $overview = '/vehicles/' . $golf->id;

        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(1, $items);
        self::assertStringContainsString('Annual service', $items[0]);
        self::assertStringContainsString('Overdue by 30 days', $items[0]);
        $logIt = 'href="/vehicles/' . $golf->id . '/maintenance/new?schedule=' . $service->id . '"';
        self::assertStringContainsString($logIt, $items[0]);

        // Dismissed through its reminder: gone, and back on the overview.
        $reminder = $this->reminderFor($golf, ReminderSource::Schedule, $service->id);
        $dismissed = $this->browser->post('/reminders/' . $reminder . '/dismiss', ['return' => $overview]);
        self::assertSame($overview, $dismissed->getHeaderLine('Location'));
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));

        // A new due point that is still overdue is a new occurrence: it comes back.
        $this->service($this->app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(
                self::date('2025-09-10'),
                MaintenanceCategory::Service,
                'Annual service',
                '0',
                scheduleId: $service->id,
            ),
            new DateTimeZone(self::LONDON),
        );
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(1, $items);
        self::assertStringContainsString('Overdue by 21 days', $items[0]);

        // With reminders off, overdue work still shows, without Dismiss.
        $this->switchOff(Feature::Reminders);
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(1, $items);
        self::assertStringNotContainsString('Dismiss', $items[0]);
        self::assertStringContainsString('Log it', $items[0]);
    }

    public function testStaleMileageOnlyWhereProjectionsNeedReadingsCountedInTheOwnersDays(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $overview = '/vehicles/' . $golf->id;
        // 59 days before 1 Oct in London (late evening UTC is the next day there).
        $this->reading($golf, '20000', '2026-08-02T23:30:00Z');

        $html = self::body($this->browser->get($overview));
        self::assertSame([], $this->items($html), 'no distance-based schedule: never nagged');

        $this->service($this->app, ScheduleService::class)->create($golf, new MaintenanceScheduleData(
            category: MaintenanceCategory::Service,
            title: 'Oil change',
            intervalKm: '15000',
            baselineDoneKm: '20000',
        ));
        self::assertSame([], $this->items(self::body($this->browser->get($overview))), '59 days: not yet');

        $readings = $this->service($this->app, OdometerService::class)->history($golf)->readings;
        $this->service($this->app, OdometerService::class)->update(
            $golf,
            $readings[0],
            new OdometerReadingData('20000', new DateTimeImmutable('2026-07-31T09:00:00Z')),
        );
        $html = self::body($this->browser->get($overview));
        $items = $this->items($html);
        self::assertCount(1, $items, '62 days');
        self::assertStringContainsString('No mileage logged since 31 Jul 2026', $items[0]);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/odometer/new"', $items[0]);

        // The owner's setting moves the threshold.
        $this->browser->get('/settings/reminders');
        $this->saveThresholds(90, 12);
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));
        $this->saveThresholds(60, 12);

        // Hidden until the latest reading changes.
        $this->browser->post('/vehicles/' . $golf->id . '/attention/hide', $this->hideForm($html));
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));
        $this->reading($golf, '20100', '2026-07-31T18:00:00Z');
        self::assertCount(1, $this->items(self::body($this->browser->get($overview))), 'a new, still old, reading');
    }

    public function testAStaleValuationFollowsTheOwnersSettingOnTheListAndTheOwnershipCard(): void
    {
        $this->start();
        $golf = $this->service($this->app, VehicleService::class)->create($this->owner($this->app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            purchaseDate: self::date('2023-01-01'),
            purchasePrice: '20000.00',
        ));
        $this->valuation($golf, '2025-04-01', '15000.00');
        $overview = '/vehicles/' . $golf->id;

        $html = self::body($this->browser->get($overview));
        $items = $this->items($html);
        self::assertCount(1, $items);
        self::assertStringContainsString('Valued 18 months ago', $items[0]);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/valuations/new"', $items[0]);
        self::assertStringContainsString('Valued 18 months ago; add a new valuation', $html, 'the Ownership card agrees');

        $this->browser->get('/settings/reminders');
        $this->saveThresholds(60, 24);
        $html = self::body($this->browser->get($overview));
        self::assertSame([], $this->items($html));
        self::assertStringNotContainsString('add a new valuation for an up-to-date figure', $html);
        $this->saveThresholds(60, 12);

        // Hidden, then a newer (still stale) valuation brings it back.
        $form = $this->hideForm(self::body($this->browser->get($overview)));
        $this->browser->post('/vehicles/' . $golf->id . '/attention/hide', $form);
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));
        $this->valuation($golf, '2025-08-01', '14000.00');
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertStringContainsString('Valued 14 months ago', implode('', $items));

        // A fresh valuation fixes it.
        $this->valuation($golf, '2026-09-01', '13000.00');
        self::assertSame([], $this->items(self::body($this->browser->get($overview))));
    }

    public function testTripsBeyondTheMileageLogAreAnItemWithoutHide(): void
    {
        $this->start(['FEATURES_TRIPS' => 'true']);
        $golf = $this->vehicle($this->app);
        $this->reading($golf, '20000', '2026-09-01T09:00:00Z');
        $this->reading($golf, '20100', '2026-09-20T09:00:00Z');
        $this->service($this->app, TripService::class)->create($golf, new TripData(
            travelledOn: self::date('2026-09-10'),
            fromPlace: 'Home',
            toPlace: 'Leeds',
            distanceKm: '400.000',
        ));

        $items = $this->items(self::body($this->browser->get('/vehicles/' . $golf->id)));
        self::assertCount(1, $items);
        self::assertStringContainsString('Business trips add up to more than the mileage log', $items[0]);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/odometer"', $items[0]);
        self::assertStringNotContainsString('Hide', $items[0]);
    }

    public function testSwitchedOffModulesTakeTheirItemsAndArchivedVehiclesRaiseNothing(): void
    {
        $this->start();
        $golf = $this->troubled();
        $overview = '/vehicles/' . $golf->id;
        self::assertCount(3, $this->items(self::body($this->browser->get($overview))));

        $this->switchOff(Feature::Compliance, Feature::Fuel);
        $items = $this->items(self::body($this->browser->get($overview)));
        self::assertCount(1, $items, 'the reading stays: the list is core');
        self::assertStringContainsString('is lower than the one before', $items[0]);

        $this->switchOff();
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $golf);
        $html = self::body($this->browser->get($overview));
        self::assertStringNotContainsString('id="attention-heading"', $html);
        self::assertStringNotContainsString('Needs attention', self::body($this->browser->get('/garage?archived=1')));
    }

    public function testTheOverviewShowsFiveAndTheRestBehindShowAll(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        // Eight readings, each lower than the one before it.
        for ($i = 0; $i < 9; $i++) {
            $this->reading($golf, (string) (30000 - 1000 * $i), sprintf('2026-0%d-01T09:00:00Z', 1 + $i));
        }

        $document = Html::document(self::body($this->browser->get('/vehicles/' . $golf->id)));
        self::assertCount(5, $document->querySelectorAll('section.card--attention > ul.attention-list > li'));
        $more = Html::element($document, 'section.card--attention details.attention-more');
        self::assertSame('Show all (8)', trim($more->querySelector('summary')->textContent ?? ''));
        self::assertCount(3, $more->querySelectorAll('li'));
    }

    public function testEachPersonSeesWhatTheyCouldFix(): void
    {
        $this->start();
        $golf = $this->troubled();
        $overview = '/vehicles/' . $golf->id;
        $viewer = $this->share($golf, ShareLevel::View, 'viewer');
        $logger = $this->share($golf, ShareLevel::Log, 'driver');
        $manager = $this->share($golf, ShareLevel::Manage, 'manager');

        // View: overdue work only, and no actions on it.
        $items = $this->items(self::body($viewer->get($overview)));
        self::assertCount(1, $items);
        self::assertStringContainsString('MOT', $items[0]);
        self::assertStringNotContainsString('Log it', $items[0]);
        self::assertStringNotContainsString('Dismiss', $items[0]);

        // Log: overdue work, and checks only on what they added.
        $items = $this->items(self::body($logger->get($overview)));
        self::assertCount(1, $items, 'the reading and fill-ups are the owner\'s');
        $driver = $this->userId('driver');
        $db = $this->connection($this->app);
        $db->update('odometer_readings', ['created_by' => $driver], ['id' => $this->backwards($golf)->id]);
        $flagged = $this->flagged($golf);
        $this->connection($this->app)->update('fuel_entries', ['created_by' => $driver], ['id' => $flagged[0]->id]);
        $items = $this->items(self::body($logger->get($overview)));
        self::assertCount(3, $items);
        self::assertStringContainsString('1 fill-up looks unusual', $items[2], 'their own fill-up only');

        // Manage: everything.
        self::assertCount(3, $this->items(self::body($manager->get($overview))));
        $items = $this->items(self::body($manager->get($overview)));
        self::assertStringContainsString('2 fill-ups look unusual', implode('', $items));

        // Hiding is refused to someone who could not see the check.
        $form = $this->hideForm(self::body($this->browser->get($overview)));
        $viewer->get($overview);
        self::assertSame(403, $viewer->post('/vehicles/' . $golf->id . '/attention/hide', $form)->getStatusCode());
    }

    public function testTheDashboardWidgetFollowsTheChipAndTheGarageCarriesAMarker(): void
    {
        $this->start();
        $golf = $this->troubled();
        $polo = $this->vehicle($this->app, 'Polo');

        $html = self::body($this->browser->get('/'));
        self::assertMatchesRegularExpression('~data-widget="needs_attention"~', $html);
        self::assertSame('needs_attention', self::firstWidget($html), 'first in a new layout');
        $widget = self::widget($html, 'needs_attention');
        self::assertSame(3, substr_count($widget, 'class="attention-item'));
        self::assertSame(3, substr_count($widget, 'Volkswagen Golf · '), 'each item names its vehicle');
        self::assertLessThan(strpos($widget, '>Check<'), strpos($widget, '>Now<'));

        // The Polo's chip: nothing needs attention, and the widget keeps its place.
        $polo = self::widget(self::body($this->browser->get('/?vehicle=' . $polo->id)), 'needs_attention');
        self::assertStringContainsString('Nothing needs attention', $polo);

        // The tiles and the garage cards carry the marker with the count in words.
        self::assertStringContainsString('Needs attention: 3 items', self::widget($html, 'fleet'));
        $garage = self::body($this->browser->get('/garage'));
        self::assertSame(1, substr_count($garage, 'class="attention-marker"'));
        self::assertStringContainsString('<span class="visually-hidden">Needs attention: 3 items</span>', $garage);
        self::assertStringContainsString('vehicles/' . $golf->id, $garage);
    }

    /**
     * @param array<string, string> $env
     */
    private function start(array $env = []): void
    {
        $this->app = $this->createRecordingApp($env + self::CHANNELS);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->day = 0;
        $this->km = 10000;
    }

    /**
     * The Golf of the acceptance criteria: an MOT that expired on 28 Sep, a
     * reading lower than the fill-up before it, and two thirsty tanks
     * among ordinary ones.
     */
    private function troubled(): Vehicle
    {
        $golf = $this->vehicle($this->app);
        $this->document($this->app, $golf, '2026-09-28', ComplianceType::Inspection, '2025-09-29');
        for ($i = 0; $i < 6; $i++) {
            $this->fill($golf, $i === 0 ? 0 : 1000, '80');
        }
        $this->fill($golf, 1000, '120');
        $this->fill($golf, 1000, '80');
        $this->fill($golf, 1000, '120');
        $this->reading($golf, '15000', '2026-06-01T09:00:00Z');

        return $golf;
    }

    private function switchOff(Feature ...$off): void
    {
        $this->service($this->app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => !in_array($f, $off, true) && $f !== Feature::Trips,
        )));
    }

    private function fill(Vehicle $vehicle, int $distance, string $litres): FuelEntry
    {
        $this->km += $distance;
        $this->day++;
        $at = (new DateTimeImmutable('2026-01-01T08:00:00Z'))->modify(sprintf('+%d days', $this->day));
        $fuel = Fuel::defaultFor($vehicle->data->fuelType);

        return $this->service($this->app, FuelService::class)->create(
            $vehicle,
            new FuelEntryData($at, (string) $this->km, $fuel, $litres, '1.5', '0', false),
        );
    }

    private function reading(Vehicle $vehicle, string $km, string $utc): void
    {
        $this->service($this->app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData($km, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
        );
    }

    private function valuation(Vehicle $vehicle, string $date, string $amount): void
    {
        $this->service($this->app, ValuationService::class)->create(
            $vehicle,
            new VehicleValuationData(self::date($date), $amount, 'Dealer'),
        );
    }

    private function backwards(Vehicle $vehicle): OdometerReading
    {
        $history = $this->service($this->app, OdometerService::class)->history($vehicle);
        foreach ($history->readings as $reading) {
            if ($history->warningFor($reading->id) !== null) {
                return $reading;
            }
        }
        self::fail('no flagged reading');
    }

    /**
     * @return list<FuelEntry>
     */
    private function flagged(Vehicle $vehicle): array
    {
        $fuel = $this->service($this->app, FuelService::class);
        $entries = $this->service($this->app, FuelEntryRepository::class);

        return array_map(
            static fn ($check): FuelEntry => $entries->find($vehicle->id, $check->entry()->id) ?? self::fail('gone'),
            $fuel->checks($fuel->history($vehicle))->flagged(),
        );
    }

    private function reminderFor(Vehicle $vehicle, ReminderSource $source, int $sourceId): int
    {
        foreach ($this->reminders($this->app) as $reminder) {
            if ($reminder->vehicleId === $vehicle->id && $reminder->source === $source && $reminder->sourceId === $sourceId) {
                return $reminder->id;
            }
        }
        self::fail('no reminder');
    }

    private function saveThresholds(int $days, int $months): void
    {
        $response = $this->browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'mileage_days' => (string) $days,
            'valuation_months' => (string) $months,
        ]);
        self::assertSame(303, $response->getStatusCode());
        $this->browser->get('/settings/reminders');
    }

    /**
     * A member with a share on the vehicle, signed in.
     */
    private function share(Vehicle $vehicle, ShareLevel $level, string $username = 'partner'): TestBrowser
    {
        $this->createMember($this->app, $username);
        self::assertNull($this->service($this->app, SharingService::class)->add($vehicle, $username, $level, true, false));

        return $this->browserFor($this->app, $username);
    }

    private function userId(string $username): int
    {
        $id = $this->connection($this->app)->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        self::assertTrue(is_numeric($id));

        return (int) $id;
    }

    /**
     * The overview card's items, as HTML, in order (both lists).
     *
     * @phpstan-impure
     * @return list<string>
     */
    private function items(string $html): array
    {
        $document = Html::document($html);
        $items = [];
        foreach ($document->querySelectorAll('section.card--attention li.attention-item') as $item) {
            $items[] = $document->saveHtml($item);
        }

        return $items;
    }

    /**
     * The first Hide form's fields on the page.
     *
     * @return array<string, string>
     */
    private function hideForm(string $html): array
    {
        $document = Html::document($html);
        $form = Html::element($document, 'form[action$="/attention/hide"]');
        $values = Html::formValues($form);
        unset($values['csrf_name'], $values['csrf_value']);

        return $values;
    }

    private static function widget(string $html, string $id): string
    {
        $document = Html::document($html);

        return $document->saveHtml(Html::element($document, '#widget-' . $id));
    }

    private static function firstWidget(string $html): string
    {
        preg_match('~data-widget="([a-z_]+)"~', $html, $m);

        return $m[1] ?? '';
    }
}
