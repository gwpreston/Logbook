<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\StoredFile;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use ZipArchive;

/**
 * The sale pack (spec.md §7.19), end to end: what a buyer sees, what they
 * never see, the mileage record, *Due next*, the MOT history line and the
 * paperwork ZIP. The owner uses UK units and GBP in Europe/London (en_GB);
 * "today" is 27 Sep 2026.
 */
final class SalePackTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    /** Amounts and words that must never reach the pack, whatever the options. */
    private const array SENTINELS = [
        '12,345' => 'the purchase price',
        '9,876' => 'the sale price',
        '71.23' => 'a fill-up',
        '43.21' => 'an expense',
        'SENTINEL-EXPENSE' => 'an expense note',
        '8,765' => 'a valuation',
        'SENTINEL-VALUATION' => 'a valuation source',
        'SENTINEL-INSURER' => 'an insurance document',
        '432.10' => 'the insurance cost',
        'SENTINEL-V5C' => 'the registration document',
        'Cost of ownership' => 'ownership costs',
        'Depreciation' => 'depreciation',
    ];

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    public function testTheSummaryAnswersABuyersQuestions(): void
    {
        $golf = $this->garage();
        $html = $this->page($golf);

        self::assertStringContainsString('Vehicle summary', $html);
        self::assertStringContainsString('GO19 ABC', $html);
        self::assertStringContainsString('WVWZZZ1KZ9W000001', $html);
        self::assertStringContainsString('7 yrs 6 mo', $html, 'first registered, with its age');
        self::assertStringContainsString('Owned since March 2021', $html);
        self::assertStringContainsString('23,874 mi covered', $html, 'from the reading on the purchase day');
        self::assertStringContainsString('48,729 mi on 12 Sept 2026', $html, 'the current odometer and its date');
        self::assertStringContainsString('a year since first registered', $html);
        self::assertStringContainsString('2 service and repair records', $html);
        self::assertStringContainsString('last serviced 12 Mar 2024 at 37,282 mi', $html, 'the last service or oil record');
        self::assertStringContainsString('2 with paperwork', $html);
        self::assertStringContainsString('MOT valid until 14 Jun 2027', $html);
        self::assertStringContainsString('Check the full MOT history at gov.uk/check-mot-history', $html);
        self::assertMatchesRegularExpression('#\d+ invoices and certificates available#', $html);

        // Entry points: the header on every tab, and History's toolbar.
        foreach (['', '/history', '/odometer', '/maintenance'] as $tab) {
            $page = self::body($this->browser->get('/vehicles/' . $golf->id . $tab));
            self::assertStringContainsString('href="/vehicles/' . $golf->id . '/sale-pack"', $page, $tab);
        }
        self::assertStringContainsString('Prepare for sale', self::body($this->browser->get('/vehicles/' . $golf->id)));
    }

    public function testNoOptionEverShowsPricesFuelExpensesValuationsOrOwnershipCosts(): void
    {
        $golf = $this->garage();
        $golf = $this->withSale($golf, '2026-09-20', '9876.54');

        foreach ([0, 1] as $due) {
            foreach ([0, 1] as $descriptions) {
                foreach ([0, 1] as $timeline) {
                    foreach (['', '1', 'true'] as $costs) {
                        $query = array_filter([
                            'options' => '1',
                            'due' => $due ? '1' : '',
                            'descriptions' => $descriptions ? '1' : '',
                            'timeline' => $timeline ? '1' : '',
                            'costs' => $costs,
                            'kinds' => ['service', 'inspection', 'photo', 'purchase', 'insurance'],
                        ]);
                        $html = $this->page($golf, $query);
                        $sheet = $this->section($html, 'sale-pack__sheet');
                        $with = ' with ' . http_build_query($query);
                        foreach (self::SENTINELS as $sentinel => $what) {
                            self::assertStringNotContainsString($sentinel, $sheet, $what . $with);
                            // On screen, only the seller's own list of the insurance files they ticked names the insurer.
                            if ($sentinel !== 'SENTINEL-INSURER') {
                                self::assertStringNotContainsString($sentinel, $html, $what . $with);
                            }
                        }
                    }
                }
            }
        }
        // Linked straight to, without the form sent, too.
        $html = $this->page($golf, ['costs' => '1', 'timeline' => '1']);
        foreach (self::SENTINELS as $sentinel => $what) {
            self::assertStringNotContainsString($sentinel, $html, $what);
        }
        self::assertStringContainsString('Owned March 2021 to September 2026', $html, 'a sold vehicle');
    }

    public function testOnlyCostsOneShowsWorkCostsAndTheirTotal(): void
    {
        $golf = $this->garage();

        foreach ([[], ['costs' => '0'], ['costs' => 'true'], ['costs' => 'yes'], ['options' => '1', 'costs' => 'on']] as $query) {
            $html = $this->page($golf, $query);
            self::assertStringNotContainsString('£420.00', $html, http_build_query($query));
            self::assertStringNotContainsString('£54.85', $html, http_build_query($query));
            self::assertStringNotContainsString('spent on servicing', $html, http_build_query($query));
        }

        $html = $this->page($golf, ['costs' => '1']);
        self::assertStringContainsString('£420.00', $html, 'each record\'s cost');
        self::assertStringContainsString('£54.85', $html, 'and the MOT\'s');
        self::assertStringContainsString('£600.00 spent on servicing and repairs since March 2024', $html);
        self::assertStringContainsString('>Bought<', $html, 'the milestone, never its price');
    }

    public function testTheMileageRecordListsOnlyReadingsABuyerCanCheck(): void
    {
        $golf = $this->garage();
        $html = $this->section($this->page($golf), 'sale-pack__mileage');

        self::assertStringContainsString('Service invoice, Kwik Fit', $html);
        self::assertStringContainsString('MOT certificate', $html);
        self::assertStringContainsString('Dashboard photo', $html);
        self::assertStringNotContainsString('Service record', $html, 'both records have invoices');
        // The fill-up (48,467 mi) and the manual reading without a photo (24,855 mi on purchase day) are not listed.
        self::assertStringNotContainsString('48,467 mi', $html);
        self::assertStringNotContainsString('24,855 mi', $html);
        self::assertSame(4, substr_count($html, '<tr>') - 1, 'four readings and the header');
        self::assertStringContainsString('data-chart=', $html, 'a chart, in the print palette');
        self::assertStringContainsString('&quot;print&quot;:true', $html);
        self::assertStringNotContainsString('looks wrong', $this->page($golf));
    }

    public function testABackwardsReadingRaisesTheSellerNoticeAndThePackStillRenders(): void
    {
        $golf = $this->garage();
        $entry = $this->service($this->app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(self::day('2026-09-15'), MaintenanceCategory::Repair, 'Wiper blades', '12.00', '50000'),
            new DateTimeZone('Europe/London'),
        );

        $response = $this->browser->get('/vehicles/' . $golf->id . '/sale-pack');
        self::assertSame(200, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('This reading looks wrong. Check it before you share the pack.', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/maintenance/' . $entry->id . '/edit"', $html);
        self::assertMatchesRegularExpression('#<section class="card no-print sale-pack__notice"#', $html, 'screen only');
        self::assertStringNotContainsString('looks wrong', $this->section($html, 'sale-pack__sheet'));
    }

    public function testDueNextIsOverdueFirstAtMostFiveWithoutCosts(): void
    {
        $golf = $this->garage();
        $schedule = $this->service($this->app, ScheduleService::class)->create($golf, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            intervalMonths: 12,
        ));
        $this->service($this->app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(
                self::day('2025-06-01'),
                MaintenanceCategory::Service,
                'Annual service',
                '240.00',
                scheduleId: $schedule->id,
            ),
            new DateTimeZone('Europe/London'),
        );
        foreach (['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01'] as $i => $on) {
            $this->service($this->app, ReminderService::class)->createManual(
                $this->owner($this->app),
                new ManualReminderData($golf->id, 'Job ' . ($i + 1), self::day($on), 7),
            );
        }

        foreach ([[], ['costs' => '1']] as $query) {
            $due = strstr($this->section($this->page($golf, $query), 'sale-pack__due'), '</dd>', true);
            self::assertIsString($due);
            self::assertStringContainsString('Annual service · Due now', $due, 'overdue first');
            self::assertSame(5, substr_count($due, '<li>'), 'at most five');
            self::assertStringContainsString('Job 3', $due, 'then by date');
            self::assertStringNotContainsString('Job 4', $due);
            self::assertStringNotContainsString('£', $due, 'never a cost');
        }

        self::assertStringNotContainsString('sale-pack__due', $this->page($golf, ['options' => '1']), 'switched off');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $golf);
        $archived = $this->browser->get('/vehicles/' . $golf->id . '/sale-pack');
        self::assertSame(200, $archived->getStatusCode(), 'archived vehicles have a pack');
        self::assertStringNotContainsString('sale-pack__due', self::body($archived), 'but no Coming up');
    }

    public function testTheMotHistoryLineNeedsAGbOwnerAndARegistration(): void
    {
        $golf = $this->garage();
        self::assertStringContainsString('gov.uk/check-mot-history', $this->page($golf));

        $owner = $this->owner($this->app);
        $prefs = $owner->preferences;
        $units = [$prefs->distanceUnit, $prefs->volumeUnit, $prefs->consumptionUnit];
        $german = new DisplayPreferences('de_DE', 'Europe/Berlin', ...[...$units, 'EUR']);
        $users = $this->service($this->app, UserRepository::class);
        $users->updateProfile($owner->id, 'Pat Owner', $german, new DateTimeImmutable());
        $de = $this->page($golf);
        self::assertStringNotContainsString('gov.uk', $de);
        self::assertStringContainsString('HU gültig bis', $de, 'the German inspection line');
        self::assertStringContainsString('Verkaufsmappe', $de);

        $users->updateProfile($owner->id, 'Pat Owner', $prefs, new DateTimeImmutable());
        $plain = $this->service($this->app, VehicleService::class)->update($owner, $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            purchaseDate: $golf->data->purchaseDate,
        ));
        self::assertStringNotContainsString('gov.uk', $this->page($plain), 'no registration');
    }

    public function testTheGroupedHistoryLeavesTheSellersDocumentsOut(): void
    {
        $golf = $this->garage();
        $html = $this->section($this->page($golf), 'sale-pack__history');

        self::assertStringContainsString('Service and repairs', $html);
        self::assertStringContainsString('Inspections and certificates', $html);
        self::assertStringContainsString('Annual service', $html);
        self::assertStringContainsString('Kwik Fit', $html);
        self::assertStringContainsString('Oil and filters changed', $html, 'descriptions on by default');
        self::assertStringContainsString('invoice.pdf', $html, 'the file names');
        self::assertStringContainsString('>First registered<', $html);
        self::assertStringContainsString('>Bought<', $html);
        self::assertStringNotContainsString('Insurance', $html);
        self::assertStringNotContainsString('SENTINEL', $html);

        $without = $this->page($golf, ['options' => '1', 'due' => '1']);
        self::assertStringNotContainsString('Oil and filters changed', $without, 'descriptions unticked');
        self::assertStringNotContainsString('Full timeline', $without);
        $timeline = $this->section($this->page($golf, ['timeline' => '1']), 'sale-pack__timeline');
        self::assertStringContainsString('Annual service', $timeline);
        self::assertStringNotContainsString('Fill-up', $timeline);
        self::assertStringNotContainsString('Insurance', $timeline);
    }

    public function testTheZipHoldsTheDefaultKindsReadablyNamed(): void
    {
        $golf = $this->garage();
        $zip = $this->zip($golf, []);

        self::assertSame([
            '2024-03-12 Service - Kwik Fit.pdf',
            '2024-03-12 Service - Kwik Fit (2).pdf',
            '2025-03-10 Repair - Brake pads.pdf',
            '2026-06-15 MOT - Autocentre.pdf',
            '2026-09-12 Dashboard photo.jpg',
            'contents.txt',
        ], $zip['names']);
        self::assertSame('%PDF-invoice.pdf', $zip['files']['2024-03-12 Service - Kwik Fit.pdf']);
        $contents = $zip['files']['contents.txt'];
        self::assertStringContainsString('Paperwork for Volkswagen Golf (GO19 ABC), 27 Sept 2026', $contents);
        $line = "2024-03-12 Service - Kwik Fit.pdf\r\n    Annual service, Kwik Fit · 12 Mar 2024 · 37,282 mi";
        self::assertStringContainsString($line, $contents);

        $response = $this->browser->get('/vehicles/' . $golf->id . '/sale-pack/paperwork.zip');
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $disposition = $response->getHeaderLine('Content-Disposition');
        self::assertStringContainsString('filename="Volkswagen Golf paperwork 2026-09-27.zip"', $disposition);
        self::assertSame((string) strlen(self::body($response)), $response->getHeaderLine('Content-Length'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testKindsAndExclusionsNeverAddANeverOfferedFile(): void
    {
        $golf = $this->garage();
        $attachments = $this->service($this->app, AttachmentRepository::class)->listForVehicle($golf->id);
        $byName = [];
        foreach ($attachments as $attachment) {
            $byName[$attachment->filename] = $attachment->id;
        }

        $chosen = $this->zip($golf, [
            'options' => '1',
            'kinds' => ['purchase', 'insurance'],
            'exclude' => [(string) $byName['insurance.pdf']],
        ]);
        $expected = ['2021-03-14 Purchase - bill of sale.pdf', 'contents.txt'];
        self::assertSame($expected, $chosen['names'], 'insurance unticked by id');

        $forged = $this->zip($golf, [
            'options' => '1',
            'kinds' => ['registration', 'sale', 'other', 'valuation', 'fuel', 'expense'],
            'exclude' => [(string) $byName['v5c.pdf']],
        ]);
        self::assertSame(['contents.txt'], $forged['names'], 'never-offered kinds name nothing');
        self::assertStringContainsString('No files were chosen.', $forged['files']['contents.txt']);

        // Another vehicle's file, by id, is ignored in keep[] and exclude[] alike.
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $other = $this->maintenance($this->app, $fiesta, '2025-01-01', 'Fiesta service', '99.00', '1000');
        $this->attach($fiesta, AttachmentOwner::Maintenance, $other->id, 'fiesta.pdf');
        $fiestaFile = $this->service($this->app, AttachmentRepository::class)->listForVehicle($fiesta->id)[0]->id;
        $kept = $this->zip($golf, [
            'options' => '1',
            'kinds' => ['service'],
            'choose' => '1',
            'keep' => [(string) $fiestaFile, (string) $byName['brakes.pdf']],
        ]);
        self::assertSame(['2025-03-10 Repair - Brake pads.pdf', 'contents.txt'], $kept['names']);
        self::assertSame(404, $this->browser->get('/vehicles/' . $fiesta->id . '999/sale-pack/paperwork.zip')->getStatusCode());
    }

    public function testChooseFilesWorksWithoutJs(): void
    {
        $golf = $this->garage();
        $attachments = $this->service($this->app, AttachmentRepository::class)->listForVehicle($golf->id);
        $brakes = array_values(array_filter($attachments, static fn ($a): bool => $a->filename === 'brakes.pdf'))[0];
        $keep = array_map(
            static fn ($a): string => (string) $a->id,
            array_filter($attachments, static fn ($a): bool => in_array($a->filename, ['invoice.pdf', 'mot.pdf'], true)),
        );

        $html = $this->page($golf, [
            'options' => '1',
            'due' => '1',
            'descriptions' => '1',
            'kinds' => ['service', 'inspection'],
            'choose' => '1',
            'keep' => array_values($keep),
        ]);

        self::assertStringContainsString('<details class="sale-pack__files" open>', $html, 'open while files are unticked');
        self::assertStringContainsString('name="keep[]" value="' . $brakes->id . '" data-sale-pack-file>', $html, 'unticked');
        if (preg_match('#href="([^"]*paperwork\.zip[^"]*)" download#', $html, $link) !== 1) {
            self::fail('No ZIP link.');
        }
        $href = html_entity_decode($link[1]);
        self::assertStringContainsString('exclude', $href);
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $page2 = array_values(array_filter($attachments, static fn ($a): bool => $a->filename === 'invoice-page2.pdf'))[0];
        self::assertSame([(string) $page2->id, (string) $brakes->id], $query['exclude'] ?? null, 'the rest are left out');
        $expected = ['2024-03-12 Service - Kwik Fit.pdf', '2026-06-15 MOT - Autocentre.pdf', 'contents.txt'];
        self::assertSame($expected, $this->zip($golf, $query)['names']);
        self::assertStringContainsString('2 files', $html, 'the count follows');
    }

    public function testASwitchedOffModulesPartsLeaveThePackAndTheZip(): void
    {
        $golf = $this->garage();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save([Feature::Fuel, Feature::Reminders, Feature::Reports, Feature::Tyres]);

        $html = $this->page($golf);
        self::assertStringNotContainsString('Servicing', $html);
        self::assertStringNotContainsString('Service and repairs', $html);
        self::assertStringNotContainsString('MOT valid until', $html);
        self::assertStringNotContainsString('Inspections and certificates', $html);
        self::assertStringNotContainsString('Service invoice', $html, 'nor their readings');
        self::assertStringNotContainsString('value="service"', $html, 'nor their paperwork');
        $zip = $this->zip($golf, ['options' => '1', 'kinds' => ['service', 'inspection', 'photo']]);
        self::assertSame(['2026-09-12 Dashboard photo.jpg', 'contents.txt'], $zip['names']);

        $toggles->save([Feature::Maintenance, Feature::Compliance]);
        self::assertStringNotContainsString('>Tyres<', $this->page($golf), 'tyres off: no tyre group');
    }

    public function testASubpathAndAHardRefresh(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/logbook/login');
        $browser->post('/logbook/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $this->app = $app;
        $golf = $this->garage();

        $pack = '/vehicles/' . $golf->id . '/sale-pack?timeline=1';
        // With the prefix, and with it stripped by the proxy.
        foreach (['/logbook' . $pack, $pack] as $path) {
            $page = $browser->get($path);
            self::assertSame(200, $page->getStatusCode(), $path);
            $html = self::body($page);
            self::assertStringContainsString('action="/logbook/vehicles/' . $golf->id . '/sale-pack"', $html);
            self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/sale-pack/paperwork.zip?', $html);
        }
        self::assertSame(200, $browser->get('/logbook/vehicles/' . $golf->id . '/sale-pack/paperwork.zip')->getStatusCode());
    }

    /**
     * The Golf: bought 14 Mar 2021, two invoiced service records, an MOT,
     * a dashboard photo, and things a buyer must never see (fuel, an
     * expense, a valuation, insurance and the V5C).
     */
    private function garage(): Vehicle
    {
        $app = $this->app;
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $golf = $this->service($app, VehicleService::class)->update($owner, $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            registration: 'GO19 ABC',
            vin: 'WVWZZZ1KZ9W000001',
            year: 2019,
            purchaseDate: self::day('2021-03-14'),
            purchasePrice: '12345.67',
            firstRegisteredOn: self::day('2019-03-14'),
        ));
        $this->attach($golf, AttachmentOwner::Purchase, $golf->id, 'bill of sale.pdf');

        // On the purchase day, no photo: the ownership span starts here, but it is not evidence.
        $this->reading($app, $golf, '40000', '2021-03-14T12:00:00Z');
        $service = $this->record(
            $golf,
            '2024-03-12',
            MaintenanceCategory::Service,
            'Annual service',
            '420.00',
            '60000',
            'Kwik Fit',
            'Oil and filters changed',
        );
        $this->attach($golf, AttachmentOwner::Maintenance, $service->id, 'invoice.pdf');
        $this->attach($golf, AttachmentOwner::Maintenance, $service->id, 'invoice-page2.pdf');
        $brakes = $this->record($golf, '2025-03-10', MaintenanceCategory::Repair, 'Brake pads', '180.00', '70000');
        $this->attach($golf, AttachmentOwner::Maintenance, $brakes->id, 'brakes.pdf');

        $mot = $this->doc(
            $golf,
            ComplianceType::Inspection,
            '2026-06-15',
            '2027-06-14',
            '54.85',
            provider: 'Autocentre',
            odometerKm: '75000',
        );
        $this->attach($golf, AttachmentOwner::Compliance, $mot->id, 'mot.pdf');
        $insurance = $this->doc(
            $golf,
            ComplianceType::Insurance,
            '2026-01-01',
            '2026-12-31',
            '432.10',
            provider: 'SENTINEL-INSURER',
        );
        $this->attach($golf, AttachmentOwner::Compliance, $insurance->id, 'insurance.pdf');
        $v5c = $this->doc($golf, ComplianceType::Registration, '2021-03-20', null, '0', title: 'SENTINEL-V5C');
        $this->attach($golf, AttachmentOwner::Compliance, $v5c->id, 'v5c.pdf');

        $this->fillUp($app, $golf, '2026-09-02T08:00:00Z', '78000', '40', '71.23');
        $photo = $this->manualReading($golf, '78421', '2026-09-12T09:00:00Z');
        $this->attach($golf, AttachmentOwner::Odometer, $photo->id, 'dash.jpg', 'image/jpeg');
        $this->expense($app, $golf, '2026-08-01', '43.21', note: 'SENTINEL-EXPENSE');
        $valuation = new VehicleValuationData(self::day('2026-09-01'), '8765.43', 'SENTINEL-VALUATION');
        $this->service($app, ValuationService::class)->create($golf, $valuation);

        return $this->service($app, VehicleService::class)->get($owner, $golf->id);
    }

    private function withSale(Vehicle $golf, string $on, string $price): Vehicle
    {
        $data = $golf->data;

        return $this->service($this->app, VehicleService::class)->update($this->owner($this->app), $golf, new VehicleData(
            $data->type,
            $data->make,
            $data->model,
            $data->fuelType,
            year: $data->year,
            registration: $data->registration,
            vin: $data->vin,
            purchaseDate: $data->purchaseDate,
            purchasePrice: $data->purchasePrice,
            saleDate: self::day($on),
            salePrice: $price,
            firstRegisteredOn: $data->firstRegisteredOn,
        ));
    }

    private function record(
        Vehicle $vehicle,
        string $on,
        MaintenanceCategory $category,
        string $title,
        string $cost,
        ?string $km,
        ?string $vendor = null,
        ?string $description = null,
    ): MaintenanceEntry {
        return $this->service($this->app, MaintenanceService::class)->create(
            $vehicle,
            new MaintenanceEntryData(self::day($on), $category, $title, $cost, $km, $vendor, $description),
            new DateTimeZone('Europe/London'),
        );
    }

    private function doc(
        Vehicle $vehicle,
        ComplianceType $type,
        string $start,
        ?string $expiry,
        string $cost,
        ?string $provider = null,
        ?string $title = null,
        ?string $odometerKm = null,
    ): ComplianceDocument {
        return $this->service($this->app, ComplianceService::class)->create(
            $vehicle,
            new ComplianceDocumentData(
                $type,
                $title,
                $provider,
                null,
                self::day($start),
                $expiry === null ? null : self::day($expiry),
                $cost,
                odometerKm: $odometerKm,
            ),
            new DateTimeZone('Europe/London'),
        );
    }

    private function manualReading(Vehicle $vehicle, string $km, string $utc): OdometerReading
    {
        return $this->service($this->app, OdometerService::class)->create(
            $vehicle,
            new OdometerReadingData($km, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
        );
    }

    /**
     * A stored file (its contents name it) attached to an entry.
     */
    private function attach(
        Vehicle $vehicle,
        AttachmentOwner $owner,
        int $ownerId,
        string $name,
        string $mime = 'application/pdf',
    ): void {
        $relative = 'attachments/' . bin2hex(random_bytes(16)) . ($mime === 'application/pdf' ? '.pdf' : '.jpg');
        $path = $this->uploadDir() . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $contents = '%PDF-' . $name;
        file_put_contents($path, $contents);
        $this->service($this->app, AttachmentService::class)
            ->record($vehicle, $owner, $ownerId, [new StoredFile($relative, $name, $mime, strlen($contents))]);
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function page(Vehicle $vehicle, array $query = []): string
    {
        $path = '/vehicles/' . $vehicle->id . '/sale-pack';
        $response = $this->browser->get($path . ($query === [] ? '' : '?' . http_build_query($query)));
        self::assertSame(200, $response->getStatusCode());

        return self::body($response);
    }

    /**
     * @param array<array-key, mixed> $query
     * @return array{names: list<string>, files: array<string, string>}
     */
    private function zip(Vehicle $vehicle, array $query): array
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Needs the zip extension to read the ZIP back.');
        }
        $url = '/vehicles/' . $vehicle->id . '/sale-pack/paperwork.zip';
        $response = $this->browser->get($url . ($query === [] ? '' : '?' . http_build_query($query)));
        self::assertSame(200, $response->getStatusCode());
        $path = $this->uploadDir() . '/out-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, self::body($response));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CHECKCONS));
        $names = [];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return ['names' => $names, 'files' => $files];
    }

    /**
     * The markup from the element whose class list starts with $class to the end.
     */
    private function section(string $html, string $class): string
    {
        $at = strpos($html, $class);
        self::assertNotFalse($at, $class);

        return substr($html, $at);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
