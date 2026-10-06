<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\SetChoice;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreCost;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Coming up end to end (docs/phases/phase-15.md §15.7): the page with and without JS,
 * the chips, the CSV, the overview card and the dashboard widget; each
 * module off removes its items; archived vehicles raise nothing; currencies
 * stay apart; and reminders and notifications are left exactly as they
 * were. The owner uses UK units and GBP; "today" is 1 Oct 2026.
 */
final class ComingUpTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T10:00:00Z';
    private const string LONDON = 'Europe/London';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    public function testThePageListsEverySourceWithLastTimesCost(): void
    {
        $this->start();
        $golf = $this->golf();

        $html = self::body($this->browser->get('/upcoming'));

        self::assertStringContainsString('<h1 class="page-header__title">Coming up</h1>', $html);
        self::assertStringContainsString('1 Oct 2026 – 30 Sept 2027', $html);

        // The service every 6 months, last done 15 Aug for £240: twice.
        self::assertSame(2, substr_count($html, '>Annual service</a>'));
        self::assertStringContainsString('<time datetime="2027-02-15">15 Feb 2027</time>', $html);
        self::assertStringContainsString('<time datetime="2027-08-15">15 Aug 2027</time>', $html);
        self::assertStringContainsString('about £240.00 (last time)', $html);

        // The insurance, renewed once at last year's premium; a replaced policy raises nothing.
        self::assertSame(1, substr_count($html, '>Renew Insurance</a>'));
        self::assertStringContainsString('about £480.00 (last time)', $html);
        self::assertStringNotContainsString('£450.00', $html, 'the replaced policy');

        // Tyres: each front its share of the £176 fitting, never the whole record twice.
        self::assertStringContainsString('Tyres: front right due in about', $html);
        self::assertStringContainsString('Tyres: front left due in about', $html);
        self::assertSame(2, substr_count($html, 'about £88.00 (last time)'));
        self::assertStringContainsString('around October 2026', $html, 'a projected date is a month');

        // A manual reminder: no cost of its own.
        self::assertStringContainsString('>Fit the roof bars</a>', $html);
        self::assertStringContainsString('href="/reminders/', $html);
        self::assertStringContainsString('<span class="visually-hidden">Cost not known</span>', $html);

        // Sections as headings, months by name, links to each source.
        self::assertStringContainsString('<time datetime="2026-11">November 2026</time></h2>', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/maintenance/schedules/', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/documents/', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/tyres"', $html);

        // 240 × 2 + 480 + 88 × 2, with the reminder unknown.
        self::assertStringContainsString('at least £1,136.00', $html);
        self::assertStringContainsString('1 item without a known cost', $html);
        self::assertStringContainsString('Nothing is adjusted for inflation.', $html, 'an estimate, and says so');
    }

    public function testWithoutJsTheChartIsATable(): void
    {
        $this->start();
        $this->golf();

        $html = self::body($this->browser->get('/upcoming'));

        self::assertStringContainsString('data-chart="', $html, 'drawn with JS');
        self::assertStringContainsString('<caption class="visually-hidden">By month</caption>', $html);
        self::assertSame(12 + 1, substr_count($html, '<th scope="row">'), 'twelve months and the total');
        self::assertStringContainsString('<th scope="row">Feb 2027</th>', $html);
    }

    public function testOverdueWorkIsListedOnceAndCountsInThisMonth(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $brakes = $this->schedule($this->app, $golf, 'Brake fluid', '2024-06-01', 24);
        $this->completed($golf, $brakes, '2024-06-01', '65.00');

        $html = self::body($this->browser->get('/upcoming'));
        $overdue = self::sectionOf($html, 'overdue-heading');

        self::assertStringContainsString('Brake fluid', $overdue);
        self::assertStringContainsString('<time datetime="2026-06-01">1 Jun 2026</time>', $overdue);
        self::assertStringContainsString('about £65.00 (last time)', $overdue);
        self::assertSame(1, substr_count($html, '>Brake fluid</a>'), 'no repeats until it is done');
        self::assertStringContainsString('<th scope="row">Oct 2026</th>', $html);
    }

    public function testTheChipsNarrowItAndFallBackToAll(): void
    {
        $this->start();
        $golf = $this->golf();
        $van = $this->car('Ford', 'Transit');
        $this->document($this->app, $van, '2027-01-31', ComplianceType::Inspection, '2026-02-01');

        $all = self::body($this->browser->get('/upcoming'));
        self::assertStringContainsString('class="vehicle-filter"', $all);
        self::assertStringContainsString('Renew Inspection (MOT)', $all);
        self::assertStringContainsString('Annual service', $all);
        self::assertStringContainsString('Ford Transit ·', $all, 'each row names its vehicle');

        $one = self::body($this->browser->get('/upcoming?vehicle=' . $van->id));
        self::assertStringContainsString('href="/upcoming?vehicle=' . $van->id . '" aria-current="page"', $one);
        self::assertStringContainsString('Renew Inspection (MOT)', $one);
        self::assertStringNotContainsString('Annual service', $one);
        self::assertStringContainsString('href="/upcoming.csv?vehicle=' . $van->id . '"', $one);

        $unknown = self::body($this->browser->get('/upcoming?vehicle=9999'));
        self::assertStringContainsString('href="/upcoming" aria-current="page"', $unknown);
        self::assertStringContainsString('Annual service', $unknown);

        // An archived vehicle raises nothing, and its id falls back to all.
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $golf);
        $archived = self::body($this->browser->get('/upcoming?vehicle=' . $golf->id));
        self::assertStringNotContainsString('Annual service', $archived);
        self::assertStringNotContainsString('Fit the roof bars', $archived);
        self::assertStringContainsString('Renew Inspection (MOT)', $archived);
        self::assertStringNotContainsString('class="vehicle-filter"', $archived, 'one active vehicle: no chips');
    }

    public function testASetAgeingIsOneItemNamedBySet(): void
    {
        $this->start();
        $van = $this->car('Ford', 'Transit');
        $zone = new DateTimeZone(self::LONDON);
        $changes = $this->service($this->app, TyreChangeService::class);
        $dot = static function (string $code): DotCode {
            $parsed = DotCode::parse($code, self::date('2026-10-01'));
            self::assertInstanceOf(DotCode::class, $parsed);

            return $parsed;
        };
        $on = $changes->existing($van, new TyreChangeData(self::date('2026-03-01'), '30000.000'), [
            new NewTyre(TyrePosition::FrontLeft, new TyreData('Continental', 'WinterContact', dot: $dot('4920'))),
            new NewTyre(TyrePosition::FrontRight, new TyreData('Continental', 'WinterContact', dot: $dot('4920'))),
            new NewTyre(TyrePosition::RearLeft, new TyreData('Continental', 'WinterContact', dot: $dot('4920'))),
            new NewTyre(TyrePosition::RearRight, new TyreData('Continental', 'WinterContact', dot: $dot('0121'))),
        ], $zone, 'en_GB');
        $changes->remove(
            $van,
            new TyreChangeData(self::date('2026-03-20'), '31000.000'),
            array_fill_keys($on->tyreIds(), null),
            new SetChoice(newSet: new TyreSetData('Winter wheels')),
            null,
            $zone,
            'en_GB',
        );

        $html = self::body($this->browser->get('/upcoming'));

        self::assertSame(1, substr_count($html, 'Tyres: Winter wheels'), 'one item for the set, named by it');
        self::assertStringContainsString('<time datetime="2026-11-30">30 Nov 2026</time>', $html, 'at its soonest limit');
        self::assertStringNotContainsString('Continental WinterContact', $html);
    }

    public function testTheCsvHasOneRowPerItemThenFuel(): void
    {
        $this->start();
        $golf = $this->golf();
        $this->driving($golf);

        $response = $this->browser->get('/upcoming.csv');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('logbook-coming-up-2026-10-01.csv', $response->getHeaderLine('Content-Disposition'));

        $rows = self::csvRows((string) $response->getBody());
        self::assertSame(
            ['Date', 'Vehicle', 'Registration', 'Source', 'Title', 'Expected cost', 'Currency', 'Projected', 'Overdue'],
            $rows[0],
        );
        $service = self::rowsWith($rows, 'Annual service');
        self::assertSame(
            ['2027-02-15', 'Volkswagen Golf', '', 'Service schedule', 'Annual service', '240.00', 'GBP', 'no', 'no'],
            $service[0],
        );
        self::assertSame('Renew Insurance', self::rowsWith($rows, 'Renew Insurance')[0][4]);
        self::assertSame('', self::rowsWith($rows, 'Fit the roof bars')[0][5], 'no known cost: empty');

        $fuel = self::rowsWith($rows, 'Fuel estimate');
        self::assertCount(12, $fuel, 'one per month');
        self::assertSame('2026-10-01', $fuel[0][0]);
        self::assertSame('Fuel 2026-10', $fuel[0][4]);
        self::assertSame('yes', $fuel[0][7], 'an estimate');
        self::assertSame('2027-09-01', $fuel[11][0]);
    }

    public function testTheFuelEstimateNeedsNinetyDaysOfFillUps(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->reading($golf, '10000', '2026-08-01T12:00:00Z');
        $this->fill($golf, '2026-08-01T12:00:00Z', '10000', '40.00', '60.00');
        $this->fill($golf, '2026-09-25T12:00:00Z', '11000', '40.00', '60.00');

        self::assertStringContainsString('Nothing planned yet', self::body($this->browser->get('/upcoming')), 'no estimate yet');

        $this->costedDocument($golf, '2025-12-01', '2026-11-30', '480.00');
        $html = self::body($this->browser->get('/upcoming'));
        self::assertStringContainsString('Not enough fill-ups yet', $html);
        self::assertStringNotContainsString('on fuel</span>', $html);

        $this->fill($golf, '2026-06-01T12:00:00Z', '9000', '40.00', '60.00');
        $this->reading($golf, '9000', '2026-06-01T11:00:00Z');
        $html = self::body($this->browser->get('/upcoming'));
        self::assertStringNotContainsString('Not enough fill-ups yet', $html);
        self::assertStringContainsString('Fuel (estimate)', $html);
        self::assertStringContainsString('on fuel', $html);
    }

    public function testTheOverviewCardShowsTheNextFive(): void
    {
        $this->start();
        $golf = $this->golf();

        $card = self::sectionOf(self::body($this->browser->get('/vehicles/' . $golf->id)), 'coming-up-heading');

        self::assertStringContainsString('Coming up', $card);
        self::assertStringContainsString('href="/upcoming?vehicle=' . $golf->id . '"', $card);
        self::assertSame(5, substr_count($card, 'class="list__item forecast-item"'));
        self::assertStringContainsString('Renew Insurance', $card);
        self::assertStringNotContainsString('Volkswagen Golf ·', $card, 'the vehicle is not repeated on its own page');
        self::assertStringContainsString('Next 12 months:', $card);
        self::assertStringContainsString('at least £1,136.00', $card);

        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $golf);
        self::assertStringNotContainsString('coming-up-heading', self::body($this->browser->get('/vehicles/' . $golf->id)));
    }

    public function testTheWidgetIsAppendedToAnOldSavedLayout(): void
    {
        $this->start();
        $golf = $this->golf();
        $this->service($this->app, SettingRepository::class)->save(
            DashboardLayoutStore::SETTING,
            [
                'order' => [
                    'spend', 'reminders', 'fleet', 'recent_fuel', 'efficiency', 'compliance', 'mileage', 'recent_activity',
                ],
                'hidden' => [],
            ],
            SettingScope::User,
            $this->owner($this->app)->id,
        );

        $html = self::body($this->browser->get('/'));
        preg_match_all('/data-widget="([a-z_]+)"/', $html, $matches);
        self::assertSame(
            ['coming_up', 'expense_breakdown', 'monthly_expenses', 'true_cost'],
            array_slice($matches[1], -4),
            'widgets from later releases go last',
        );

        $widget = self::sectionOf($html, 'widget-coming_up-title');
        self::assertStringContainsString('href="/upcoming"', $widget);
        self::assertStringContainsString('Renew Insurance', $widget);

        $this->car('Ford', 'Transit');
        $pinned = self::sectionOf(self::body($this->browser->get('/?vehicle=' . $golf->id)), 'widget-coming_up-title');
        self::assertStringContainsString('href="/upcoming?vehicle=' . $golf->id . '"', $pinned, 'View all keeps the vehicle');
    }

    public function testEachModuleOffRemovesItsItems(): void
    {
        $cases = [
            'maintenance' => ['Annual service', 'about £88.00'],
            'compliance' => ['Renew Insurance'],
            'tyres' => ['Tyres: front'],
            'reminders' => ['Fit the roof bars'],
            'fuel' => ['on fuel</span>'],
        ];
        foreach ($cases as $module => $gone) {
            $this->start(['FEATURES_' . strtoupper($module) => 'false']);
            $golf = $this->golf();
            $this->driving($golf);

            $html = self::body($this->browser->get('/upcoming'));
            self::assertStringContainsString('page-header__title">Coming up</h1>', $html, "$module off: the page stays");
            foreach ($gone as $text) {
                self::assertStringNotContainsString($text, $html, "$module off");
            }
            foreach (array_diff_key($cases, [$module => true]) as $kept) {
                if ($module === 'maintenance' && $kept[0] === 'Tyres: front') {
                    continue;
                }
                self::assertStringContainsString($kept[0], $html, "$module off leaves the others");
            }
        }
    }

    public function testCurrenciesStayApart(): void
    {
        $this->start();
        $this->golf();
        $kia = $this->car('Kia', 'EV6', 'EUR');
        $this->costedDocument($kia, '2026-02-10', '2027-02-09', '640.00');

        $html = self::body($this->browser->get('/upcoming'));

        self::assertStringContainsString('Next 12 months in British Pound', $html);
        self::assertStringContainsString('Next 12 months in Euro', $html);
        self::assertStringContainsString('about €640.00 (last time)', $html);
        self::assertStringContainsString('at least £1,136.00', $html, 'the euros are not added in');
    }

    public function testRemindersAndNotificationsAreLeftAsTheyWere(): void
    {
        $this->start();
        $golf = $this->golf();
        $this->service($this->app, ReminderSync::class)->sync($this->owner($this->app));
        $reminders = $this->service($this->app, ReminderService::class);
        foreach ($this->reminders($this->app) as $reminder) {
            if ($reminder->source === ReminderSource::Schedule) {
                $reminders->dismiss($reminder);
            }
        }
        $before = $this->snapshot();

        $html = self::body($this->browser->get('/upcoming'));
        $this->browser->get('/upcoming.csv');
        $this->browser->get('/vehicles/' . $golf->id);

        self::assertSame($before, $this->snapshot(), 'nothing written');
        self::assertSame([], $this->mail->sent, 'nothing sent');
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('>Annual service</a>', $html, 'a dismissed nudge does not cancel the service');
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * @param array<string, string> $env
     */
    private function start(array $env = []): void
    {
        $this->app = $this->createRecordingApp($env + self::CHANNELS);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    /**
     * The Golf: a service every 6 months (last done 15 Aug 2026 for £240), an
     * annual policy renewing 30 Nov 2026 (£480; last year's replaced), two
     * fronts fitted for £176 wearing out in October and November, and a
     * manual reminder for June.
     */
    private function golf(): Vehicle
    {
        $golf = $this->vehicle($this->app);
        $service = $this->schedule($this->app, $golf, 'Annual service', '2026-02-15', 6);
        $this->completed($golf, $service, '2026-08-15', '240.00');
        $this->costedDocument($golf, '2024-12-01', '2025-11-30', '450.00');
        $this->costedDocument($golf, '2025-12-01', '2026-11-30', '480.00');

        $zone = new DateTimeZone(self::LONDON);
        $fitted = $this->service($this->app, TyreChangeService::class)->fit(
            $golf,
            new TyreChangeData(self::date('2026-01-01'), '10000.000'),
            [
                new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'), '8.000'),
                new NewTyre(TyrePosition::FrontRight, new TyreData('Michelin', 'Primacy 4'), '8.000'),
            ],
            [],
            new TyreCost('176.00', 'Kwik Fit'),
            $zone,
            'en_GB',
        );
        [$fl, $fr] = $fitted->tyreIds();
        $this->service($this->app, TyreChangeService::class)->check(
            $golf,
            new TyreChangeData(self::date('2026-09-01'), '20000.000'),
            [$fl => '3.800', $fr => '3.500'],
            $zone,
            'en_GB',
        );
        $this->reading($golf, '20500', '2026-09-28T09:00:00Z');

        $this->service($this->app, ReminderService::class)->createManual(
            $this->owner($this->app),
            new ManualReminderData($golf->id, 'Fit the roof bars', self::date('2027-06-01'), 7),
        );

        return $golf;
    }

    /**
     * Fill-ups over the last 12 months, so the fuel estimate is ready.
     */
    private function driving(Vehicle $vehicle): void
    {
        $this->fill($vehicle, '2026-01-01T12:00:00Z', '10000', '40.00', '60.00');
        $this->fill($vehicle, '2026-05-01T12:00:00Z', '15000', '40.00', '60.00');
        $this->fill($vehicle, '2026-09-20T12:00:00Z', '20400', '40.00', '60.00');
    }

    private function car(string $make, string $model, ?string $currency = null): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create(
            $this->owner($this->app),
            new VehicleData(VehicleType::Car, $make, $model, FuelType::Petrol, currency: $currency),
        );
    }

    private function completed(Vehicle $vehicle, MaintenanceSchedule $schedule, string $on, string $cost): void
    {
        $this->service($this->app, MaintenanceService::class)->create(
            $vehicle,
            new MaintenanceEntryData(
                self::date($on),
                MaintenanceCategory::Service,
                $schedule->data->title,
                $cost,
                scheduleId: $schedule->id,
            ),
            new DateTimeZone(self::LONDON),
        );
    }

    private function costedDocument(Vehicle $vehicle, string $start, string $expiry, string $cost): void
    {
        $this->service($this->app, ComplianceService::class)->create(
            $vehicle,
            new ComplianceDocumentData(
                ComplianceType::Insurance,
                startOn: self::date($start),
                expiryOn: self::date($expiry),
                cost: $cost,
            ),
            new DateTimeZone(self::LONDON),
        );
    }

    private function fill(Vehicle $vehicle, string $utc, string $km, string $litres, string $total): void
    {
        $at = new DateTimeImmutable($utc);
        $this->service($this->app, FuelService::class)->create(
            $vehicle,
            new FuelEntryData($at, $km . '.000', Fuel::Petrol, $litres, Decimal::divide($total, $litres, 6), $total),
        );
    }

    private function reading(Vehicle $vehicle, string $km, string $utc): void
    {
        $this->service($this->app, OdometerService::class)
            ->create($vehicle, new OdometerReadingData($km, new DateTimeImmutable($utc)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return $this->connection($this->app)->fetchAllAssociative('SELECT * FROM reminders ORDER BY id');
    }

    private static function sectionOf(string $html, string $headingId): string
    {
        $at = strpos($html, 'aria-labelledby="' . $headingId . '"');
        self::assertNotFalse($at, $headingId);
        $end = strpos($html, '</section>', $at);

        return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRows(string $csv): array
    {
        self::assertStringStartsWith(CsvWriter::BOM, $csv);
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, strlen(CsvWriter::BOM)));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private static function rowsWith(array $rows, string $text): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => in_array($text, $row, true)));
    }
}
