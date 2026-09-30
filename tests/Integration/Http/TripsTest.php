<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MileageRateSetRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\SavedJourneyRepository;
use Logbook\Repository\TripRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\View\View;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Trips (Phase 22, spec.md §7.22, §7.23): the module switch, the trip form
 * and its rules, saved journeys and *Log again*, the business and private
 * split, access and privacy, the claim report with print and CSV, Settings →
 * Trips and the GB rates, and CSV export and import.
 */
final class TripsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-30T12:00:00Z';

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

    public function testTheModuleIsOffByDefaultAndItsPagesAreGone(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;

        $trips = '/vehicles/' . $id . '/trips';
        $paths = [$trips, $trips . '/new', '/trips/claim', '/settings/trips', '/log/new/trip'];
        foreach ($paths as $path) {
            self::assertSame(404, $browser->get($path)->getStatusCode(), $path);
        }
        $overview = self::body($browser->get('/vehicles/' . $id));
        self::assertStringNotContainsString('/trips', $overview, 'no tab');
        self::assertStringNotContainsString('Business', self::body($browser->get('/vehicles/' . $id . '/odometer')), 'no split');
        self::assertStringNotContainsString('Log trip', self::body($browser->get('/log/new')));
        self::assertStringNotContainsString('Trips and mileage claims', self::body($browser->get('/settings')));
        $manifest = self::body($browser->get('/manifest.webmanifest'));
        self::assertStringNotContainsString('/log/new/trip', $manifest);
    }

    public function testSwitchedOnEverythingAppearsAndSwitchedOffTheDataIsKept(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $id = (string) $golf->id;
        $this->logTrip($browser, $golf, ['distance' => '54']);

        self::assertStringContainsString('/vehicles/' . $id . '/trips', self::body($browser->get('/vehicles/' . $id)));
        self::assertStringContainsString('Trip', self::body($browser->get('/log/new')));
        self::assertStringContainsString('Trips and mileage claims', self::body($browser->get('/settings')));
        self::assertStringContainsString('/log/new/trip', self::body($browser->get('/manifest.webmanifest')));

        // Settings → Modules: off keeps the data, on restores it.
        $browser->get('/settings/modules');
        $modules = array_fill_keys(['fuel', 'maintenance', 'compliance', 'reminders', 'reports', 'tyres'], '1');
        $browser->post('/settings/modules', $modules);
        self::assertSame(404, $browser->get('/vehicles/' . $id . '/trips')->getStatusCode());
        self::assertCount(1, $this->service($app, TripRepository::class)->listForVehicle($golf->id));

        $browser->get('/settings/modules');
        $browser->post('/settings/modules', $modules + ['trips' => '1']);
        self::assertStringContainsString('Ballymena → Belfast', self::body($browser->get('/vehicles/' . $id . '/trips')));
    }

    public function testLoggingABusinessTripTakesOneForm(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $base = '/vehicles/' . $golf->id . '/trips';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('value="2026-09-30"', $form, 'today by default');
        self::assertMatchesRegularExpression('/name="is_business" value="1"\s+checked/', $form, 'business by default');
        self::assertStringContainsString('commuting, not business mileage', $form, 'the GB hint');
        self::assertStringContainsString('name="attachments[]" type="file" multiple', $form);

        $parking = ['attachments' => [$this->png('parking.png')]];
        $saved = $browser->post($base . '/new', self::fields(['distance' => '54']), $parking);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        self::assertSame($base, $saved->getHeaderLine('Location'));

        $trips = $this->service($app, TripRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $trips);
        self::assertSame('86.905', $trips[0]->data->distanceKm, '54 mi in km');
        self::assertSame('Client meeting', $trips[0]->data->purpose);

        $list = self::body($browser->follow($saved));
        self::assertStringContainsString('Trip saved: Ballymena → Belfast.', $list);
        self::assertStringContainsString('Ballymena → Belfast', $list);
        self::assertStringContainsString('54.0 mi', $list);
        self::assertStringContainsString('£29.70', $list, '54 mi at 55p this tax year');
        self::assertStringContainsString('Log again', $list);

        // The modal: 204 with where to go.
        $modal = $browser->post($base . '/new', self::fields(['distance' => '10']), headers: [View::MODAL_HEADER => '1']);
        self::assertSame(204, $modal->getStatusCode(), self::body($modal));
    }

    public function testAReturnJourneyDoublesTheOneWayDistance(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->logTrip($browser, $golf, ['distance' => '27', 'is_return' => '1', 'to_place' => 'Client site']);

        $trip = $this->service($app, TripRepository::class)->listForVehicle($golf->id)[0];
        self::assertTrue($trip->data->isReturn);
        self::assertSame('86.905', $trip->data->distanceKm, 'the round trip: 54 mi');
        $list = self::body($browser->get('/vehicles/' . $golf->id . '/trips'));
        self::assertStringContainsString('Ballymena → Client site → Ballymena', $list);

        $edit = self::body($browser->get('/vehicles/' . $golf->id . '/trips/' . $trip->id . '/edit'));
        self::assertStringContainsString('value="27"', $edit, 'the edit form shows one way again');
        self::assertStringContainsString('Distance, one way', $edit);
    }

    public function testTheOdometerGivesTheDistanceAndAMismatchIsRefused(): void
    {
        [$app, $browser, $golf] = $this->golf();

        $this->logTrip($browser, $golf, ['odometer_start' => '12000', 'odometer_end' => '12054.2']);
        $trip = $this->service($app, TripRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame('54.200', DistanceUnit::Mile->fromKmDecimal($trip->data->distanceKm, 3));
        self::assertNotNull($trip->data->odometerStartKm);
        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id);
        self::assertSame([], $readings, 'a trip writes no reading');

        $base = '/vehicles/' . $golf->id . '/trips/new';
        $browser->get($base);
        $mismatch = ['odometer_start' => '12000', 'odometer_end' => '12054.2', 'distance' => '60'];
        $refused = $browser->post($base, self::fields($mismatch));
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('The odometer says 54.2 mi; the distance says 60 mi.', self::body($refused));

        // Within half a mile is fine.
        $this->logTrip($browser, $golf, ['odometer_start' => '13000', 'odometer_end' => '13054.2', 'distance' => '54.5']);

        $backwards = $browser->post($base, self::fields(['odometer_start' => '12054', 'odometer_end' => '12000']));
        self::assertSame(422, $backwards->getStatusCode());
        self::assertStringContainsString('The end must be more than the start.', self::body($backwards));

        $one = $browser->post($base, self::fields(['odometer_start' => '12000']));
        self::assertStringContainsString('Give both odometer readings, or neither.', self::body($one));
    }

    public function testRulesTheFormEnforces(): void
    {
        [, $browser, $golf] = $this->golf();
        $base = '/vehicles/' . $golf->id . '/trips/new';
        $browser->get($base);

        $noPurpose = $browser->post($base, self::fields(['distance' => '10', 'purpose' => '']));
        self::assertSame(422, $noPurpose->getStatusCode());
        self::assertStringContainsString('A business trip needs a purpose.', self::body($noPurpose));

        $private = $browser->post($base, self::fields(['distance' => '10', 'purpose' => '', 'is_business' => '0']));
        self::assertSame(303, $private->getStatusCode(), 'a private trip needs none');

        $future = $browser->post($base, self::fields(['distance' => '10', 'travelled_on' => '2026-10-01']));
        self::assertStringContainsString('The date can’t be in the future.', self::body($future));

        $noDistance = $browser->post($base, self::fields([]));
        self::assertStringContainsString('Give the distance, or the odometer at the start and the end.', self::body($noDistance));

        $zero = $browser->post($base, self::fields(['distance' => '0']));
        self::assertSame(303, $zero->getStatusCode(), 'a distance of 0 is valid');
    }

    public function testAnArchivedVehicleTakesNoNewTrips(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);

        $response = $browser->post('/vehicles/' . $golf->id . '/trips/new', self::fields(['distance' => '10']));
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('archived', self::body($browser->follow($response)));
        self::assertSame([], $this->service($app, TripRepository::class)->listForVehicle($golf->id));
    }

    public function testSaveAsAJourneyThenPrefillItWithoutJsAndLogAgain(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->logTrip($browser, $golf, [
            'to_place' => 'Client site',
            'distance' => '27',
            'is_return' => '1',
            'purpose' => 'Site visit',
            'passengers' => '1',
            'odometer_start' => '',
            'save_journey' => '1',
        ]);
        $journeys = $this->service($app, SavedJourneyRepository::class)->listForUser($this->owner($app)->id);
        self::assertCount(1, $journeys);
        self::assertSame('43.453', $journeys[0]->data->distanceKm, 'one way: half of 54 mi');
        self::assertTrue($journeys[0]->data->isReturnDefault);

        $base = '/vehicles/' . $golf->id . '/trips/new';
        $picker = self::body($browser->get($base));
        self::assertStringContainsString('data-trip-journey', $picker);
        self::assertStringContainsString('Ballymena → Client site → Ballymena', $picker);

        $prefilled = self::body($browser->get($base . '?journey=' . $journeys[0]->id));
        self::assertStringContainsString('value="Client site"', $prefilled);
        self::assertStringContainsString('value="27"', $prefilled);
        self::assertStringContainsString('value="Site visit"', $prefilled);
        self::assertMatchesRegularExpression('/name="is_return" value="1" data-trip-return\s+checked/', $prefilled);

        $trip = $this->service($app, TripRepository::class)->listForVehicle($golf->id)[0];
        $again = self::body($browser->get($base . '?again=' . $trip->id));
        self::assertStringContainsString('value="Client site"', $again);
        self::assertStringContainsString('value="2026-09-30"', $again, 'today, not the old date');
        self::assertStringContainsString('name="passengers" type="number" value="1"', $again);
        self::assertStringNotContainsString('name="odometer_start" type="number" value="1', $again);

        // Two taps: log it again as it is.
        $again = ['to_place' => 'Client site', 'distance' => '27', 'is_return' => '1', 'purpose' => 'Site visit'];
        $this->logTrip($browser, $golf, $again);
        self::assertCount(2, $this->service($app, TripRepository::class)->listForVehicle($golf->id));

        // Settings → Trips: edit, reorder and delete; the trips stay.
        $settings = self::body($browser->get('/settings/trips'));
        self::assertStringContainsString('Saved journeys', $settings);
        $browser->get('/settings/trips/journeys/' . $journeys[0]->id . '/delete');
        $browser->post('/settings/trips/journeys/' . $journeys[0]->id . '/delete', []);
        self::assertSame([], $this->service($app, SavedJourneyRepository::class)->listForUser($this->owner($app)->id));
        self::assertCount(2, $this->service($app, TripRepository::class)->listForVehicle($golf->id));
    }

    public function testPrivateIsTheMileageLogMinusBusiness(): void
    {
        [$app, $browser, $golf] = $this->golf();
        // 1,000 mi driven this tax year (readings in km).
        $this->reading($app, $golf, '16093.440', '2026-04-10T09:00:00Z');
        $this->reading($app, $golf, '17702.784', '2026-09-25T09:00:00Z');
        $this->logTrip($browser, $golf, ['distance' => '200', 'travelled_on' => '2026-06-01']);
        $private = ['distance' => '50', 'travelled_on' => '2026-06-02', 'is_business' => '0', 'purpose' => ''];
        $this->logTrip($browser, $golf, $private);

        $trips = self::body($browser->get('/vehicles/' . $golf->id . '/trips'));
        self::assertMatchesRegularExpression('/Business<\/dt>\s*<dd[^>]*>200 mi</', $trips);
        $private800 = '/Private<\/dt>\s*<dd[^>]*>800 mi</';
        self::assertMatchesRegularExpression($private800, $trips, 'logged private trips never change the split');

        $mileage = self::body($browser->get('/vehicles/' . $golf->id . '/odometer'));
        self::assertMatchesRegularExpression('/Private<\/dt>\s*<dd[^>]*>800 mi</', $mileage);

        // More business than the log shows: "—" and the notice.
        $this->logTrip($browser, $golf, ['distance' => '900', 'travelled_on' => '2026-07-01']);
        $sparse = self::body($browser->get('/vehicles/' . $golf->id . '/trips'));
        self::assertMatchesRegularExpression('/Private<\/dt>\s*<dd[^>]*>—</', $sparse);
        self::assertStringContainsString('Add an odometer reading to fix it.', $sparse);
    }

    public function testDriversSeeTheirOwnTripsAndManagersEveryones(): void
    {
        [$app, $owner, $golf] = $this->golf();
        $this->logTrip($owner, $golf, ['to_place' => 'Owners client', 'distance' => '40'], [$this->png('toll.png')]);
        $ownersTrip = $this->service($app, TripRepository::class)->listForVehicle($golf->id)[0];
        $file = $this->connection($app)->fetchOne('SELECT id FROM attachments WHERE owner_type = ?', ['trip']);
        self::assertTrue(is_int($file) || is_string($file));
        $file = (string) $file;

        $driver = $this->shared($app, $golf, ShareLevel::Log, 'driver');
        $this->logTrip($driver, $golf, ['to_place' => 'Drivers client', 'distance' => '30']);
        $base = '/vehicles/' . $golf->id . '/trips';

        $mine = self::body($driver->get($base));
        self::assertStringContainsString('Drivers client', $mine);
        self::assertStringNotContainsString('Owners client', $mine, 'destinations are personal');
        self::assertStringContainsString('Only your own trips are shown', $mine);
        self::assertSame(404, $driver->get($base . '/' . $ownersTrip->id . '/edit')->getStatusCode());
        $receipt = '/vehicles/' . $golf->id . '/attachments/' . $file;
        self::assertSame(404, $driver->get($receipt)->getStatusCode(), 'nor its receipt');
        self::assertSame(200, $owner->get($receipt)->getStatusCode());
        $history = self::body($driver->get('/vehicles/' . $golf->id . '/history?kind=trips'));
        self::assertStringContainsString('Drivers client', $history);
        self::assertStringNotContainsString('Owners client', $history, 'nor in the Trips chip');

        $all = self::body($owner->get($base));
        self::assertStringContainsString('Owners client', $all);
        self::assertStringContainsString('Drivers client', $all);

        $manager = $this->shared($app, $golf, ShareLevel::Manage, 'manager');
        self::assertStringContainsString('Drivers client', self::body($manager->get($base)));

        // Claims only ever include the claimant's own trips.
        $claim = self::body($driver->get('/trips/claim'));
        self::assertStringContainsString('Drivers client', $claim);
        self::assertStringNotContainsString('Owners client', $claim);
        self::assertStringContainsString('£16.50', $claim, '30 mi at 55p');
        self::assertStringNotContainsString('Drivers client', self::body($owner->get('/trips/claim')));

        // A View user sees the total only, and no destinations.
        $viewer = $this->shared($app, $golf, ShareLevel::View, 'viewer');
        $view = self::body($viewer->get($base));
        self::assertStringNotContainsString('client', $view);
        self::assertStringContainsString('some trips are other drivers’', $view);
        self::assertStringNotContainsString('Log trip', $view);
    }

    public function testTripsStayOutOfEverythingRecentActivityPrintAndTheSalePack(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $secret = ['to_place' => 'Secret destination', 'distance' => '12'];
        $this->logTrip($browser, $golf, $secret, [$this->png('toll-receipt.png')]);
        $id = (string) $golf->id;

        $vehicle = '/vehicles/' . $id;
        $pages = [$vehicle . '/history', '/history', '/', $vehicle, $vehicle . '/history/print?costs=1', $vehicle . '/sale-pack'];
        foreach ($pages as $page) {
            $response = $browser->get($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            $body = self::body($response);
            $at = strpos($body, 'Secret destination');
            self::assertFalse($at, $page . ': ' . ($at === false ? '' : substr($body, max(0, $at - 400), 500)));
        }
        $kinds = 'options=1&kinds[]=service&kinds[]=inspection&kinds[]=photo&kinds[]=purchase';
        $zip = $browser->get('/vehicles/' . $id . '/sale-pack/paperwork.zip?' . $kinds);
        self::assertStringNotContainsString('toll-receipt', self::body($zip), 'nor its receipt in the ZIP');
        $print = self::body($browser->get('/vehicles/' . $id . '/history/print?options=1&kinds[]=trips'));
        self::assertStringNotContainsString('Secret destination', $print, 'not even when asked for');

        $chip = self::body($browser->get('/vehicles/' . $id . '/history?kind=trips'));
        self::assertStringContainsString('Ballymena → Secret destination', $chip, 'the Trips chip lists them');
        self::assertStringContainsString('Business trip', $chip);
    }

    public function testTheClaimReportPrintsAndExports(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->logTrip($browser, $golf, ['distance' => '54', 'passengers' => '2', 'travelled_on' => '2026-05-01']);
        $this->logTrip($browser, $golf, ['distance' => '20', 'travelled_on' => '2026-04-05', 'to_place' => 'Last year']);
        $this->logTrip($browser, $golf, [
            'distance' => '15',
            'travelled_on' => '2026-05-02',
            'is_business' => '0',
            'purpose' => '',
            'to_place' => 'Private place',
        ]);

        $claim = self::body($browser->get('/trips/claim'));
        self::assertStringContainsString('Mileage claim', $claim);
        self::assertStringContainsString('Pat Owner', $claim, 'the claimant in the print header');
        self::assertStringContainsString('GO19 ABC', $claim);
        self::assertStringContainsString('Tax year 2026/27', $claim);
        self::assertStringContainsString('HMRC approved mileage allowance payments', $claim);
        self::assertStringContainsString('£29.70', $claim, '54 mi at 55p');
        self::assertStringContainsString('£5.40', $claim, '2 passengers × 54 mi × 5p');
        self::assertStringContainsString('£35.10', $claim, 'the total approved amount');
        self::assertStringNotContainsString('Last year', $claim, '5 April is in 2025/26');
        $at = strpos($claim, 'Private place');
        self::assertFalse($at, 'private trips never appear: ' . ($at === false ? '' : substr($claim, max(0, $at - 400), 500)));
        self::assertStringContainsString('Signed', $claim);

        $last = self::body($browser->get('/trips/claim?year=2025'));
        self::assertStringContainsString('Last year', $last);
        self::assertStringContainsString('£9.00', $last, '20 mi at 45p');

        $csv = $browser->get('/trips/claim.csv');
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('mileage-claim-2026-27.csv', $csv->getHeaderLine('Content-Disposition'));
        $lines = explode("\n", trim(self::body($csv)));
        $header = 'Date,Vehicle,Registration,Journey,Purpose,Distance,Unit,Passengers,Rate,Passenger amount,Amount,Currency';
        self::assertStringContainsString($header, $lines[0]);
        $row = '2026-05-01,Volkswagen Golf,GO19 ABC,Ballymena → Belfast,Client meeting,54,mi,2,0.55,5.40,35.10,GBP';
        self::assertStringContainsString($row, $lines[1]);
    }

    public function testAClaimForOneCarOrPartOfTheYearKeepsTheYearsSplit(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->logTrip($browser, $golf, ['distance' => '9990', 'travelled_on' => '2026-05-01', 'to_place' => 'Far away']);
        $this->logTrip($browser, $polo, ['distance' => '20', 'travelled_on' => '2026-06-10', 'to_place' => 'Crossing']);

        $split = '10 × £0.55<br>10 × £0.25';
        $full = self::body($browser->get('/trips/claim'));
        self::assertStringContainsString($split, $full);
        $oneCar = self::body($browser->get('/trips/claim?vehicles[]=' . $polo->id));
        self::assertStringContainsString($split, $oneCar, 'the other car still counts towards the threshold');
        self::assertStringNotContainsString('Far away', $oneCar);
        $june = self::body($browser->get('/trips/claim?period=custom&from=2026-06-01&to=2026-06-30'));
        self::assertStringContainsString($split, $june, 'May still counts');
        self::assertStringContainsString('£8.00', $june, '10 mi at 55p and 10 at 25p');
    }

    public function testEmployerRatesShowTheDifference(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $browser->get('/settings/trips');
        $sets = $this->service($app, MileageRateSetRepository::class)->listForUser($this->owner($app)->id);
        self::assertCount(2, $sets, 'HMRC’s two sets');
        $current = $sets[0];
        $browser->get('/settings/trips/rates/' . $current->id . '/edit');
        $saved = $browser->post('/settings/trips/rates/' . $current->id . '/edit', [
            'effective_from' => '2026-04-06', 'distance_unit' => 'mi', 'currency' => 'GBP', 'car_rate' => '0.55',
            'car_threshold' => '10000', 'car_rate_after' => '0.25', 'bike_rate' => '0.24', 'passenger_rate' => '0.05',
            'employer_car_rate' => '0.35', 'source' => 'HMRC approved mileage allowance payments',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $this->logTrip($browser, $golf, ['distance' => '100', 'travelled_on' => '2026-05-01']);

        $claim = self::body($browser->get('/trips/claim'));
        self::assertStringContainsString('Paid by employer', $claim);
        self::assertStringContainsString('£35.00', $claim);
        self::assertStringContainsString('Approved amount not paid (you may be able to claim tax relief on this)', $claim);
        self::assertStringContainsString('£20.00', $claim);
    }

    public function testGbRatesAreProvidedOnceAndOthersGetNone(): void
    {
        [$app, $browser] = $this->golf();
        $rates = $this->service($app, MileageRateSetRepository::class);
        $owner = $this->owner($app);

        $page = self::body($browser->get('/settings/trips'));
        self::assertStringContainsString('From 6 Apr 2026', $page);
        self::assertStringContainsString('From 6 Apr 2011', $page);
        self::assertStringContainsString('£0.55 per mile', $page);
        self::assertCount(2, $rates->listForUser($owner->id));

        $browser->get('/settings/trips');
        self::assertCount(2, $rates->listForUser($owner->id), 'never provided twice');
        foreach ($rates->listForUser($owner->id) as $set) {
            $browser->get('/settings/trips/rates/' . $set->id . '/delete');
            $browser->post('/settings/trips/rates/' . $set->id . '/delete', []);
        }
        $browser->get('/settings/trips');
        $browser->get('/trips/claim');
        self::assertSame([], $rates->listForUser($owner->id), 'deleted rates never come back');

        $member = $this->createMember($app, 'berlin', new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::LitresPer100Km,
            'EUR',
        ));
        $german = $this->browserFor($app, 'berlin');
        $german->get('/settings/trips');
        self::assertSame([], $rates->listForUser($member->id), 'no rates outside GB');
    }

    public function testRateSetRules(): void
    {
        [, $browser] = $this->golf();
        $browser->get('/settings/trips/rates/new');
        $fields = ['effective_from' => '2027-04-06', 'distance_unit' => 'mi', 'currency' => 'GBP', 'car_rate' => '0.60'];

        $noAfter = $browser->post('/settings/trips/rates/new', $fields + ['car_threshold' => '10000']);
        self::assertSame(422, $noAfter->getStatusCode());
        self::assertStringContainsString('A threshold needs the rate after it.', self::body($noAfter));

        $taken = $browser->post('/settings/trips/rates/new', ['effective_from' => '2026-04-06'] + $fields);
        self::assertStringContainsString('You already have rates starting on this date.', self::body($taken));

        self::assertSame(303, $browser->post('/settings/trips/rates/new', $fields)->getStatusCode());
    }

    public function testTheTaxYearSettingMovesTheClaimYear(): void
    {
        [, $browser, $golf] = $this->golf();
        $this->logTrip($browser, $golf, ['distance' => '10', 'travelled_on' => '2026-02-01']);
        $browser->get('/settings/trips');
        $saved = $browser->post('/settings/trips', [
            'tax_year_day' => '1',
            'tax_year_month' => '1',
            'declaration' => 'I confirm these journeys were for business.',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));

        $claim = self::body($browser->get('/trips/claim'));
        self::assertStringContainsString('Tax year 2026', $claim);
        self::assertStringContainsString('I confirm these journeys were for business.', $claim);

        $bad = $browser->post('/settings/trips', ['tax_year_day' => '31', 'tax_year_month' => '2']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('That month has no such day.', self::body($bad));
    }

    public function testCsvExportImportsBackAsDuplicates(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->logTrip($browser, $golf, ['distance' => '27', 'is_return' => '1', 'to_place' => 'Client site']);
        $this->logTrip($browser, $golf, ['odometer_start' => '12000', 'odometer_end' => '12054.2', 'to_place' => 'Depot']);

        $export = $browser->get('/vehicles/' . $golf->id . '/export/trips.csv');
        self::assertSame(200, $export->getStatusCode());
        $csv = self::body($export);
        $header = 'Date,From,To,Return,Distance (mi),Odometer start (mi),Odometer end (mi),Business,Purpose,Passengers,Notes';
        self::assertStringContainsString($header, $csv);
        $return = '2026-09-30,Ballymena,Client site,yes,54,,,yes,Client meeting,0,';
        self::assertStringContainsString($return, $csv, 'the whole trip');
        self::assertStringContainsString('2026-09-30,Ballymena,Depot,no,54.2,12000,12054.2,yes,Client meeting,0,', $csv);

        // Into a second car: nothing doubled.
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        self::assertStringContainsString('2 rows were imported.', $this->import($browser, $polo, $csv));
        $copies = $this->service($app, TripRepository::class)->listForVehicle($polo->id);
        self::assertCount(2, $copies);
        $return = array_values(array_filter($copies, static fn ($t) => $t->data->isReturn))[0];
        self::assertSame('86.905', $return->data->distanceKm);

        // Again: both are already there.
        self::assertStringContainsString('Already logged', $this->import($browser, $polo, $csv, false));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser, 2: Vehicle}
     */
    private function golf(): array
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        return [$app, $browser, $this->vehicle($app)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function shared(App $app, Vehicle $vehicle, ShareLevel $level, string $username): TestBrowser
    {
        $member = $this->createMember($app, $username, displayName: ucfirst($username));
        $since = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $this->service($app, VehicleShareRepository::class)
            ->insert($vehicle->id, $member->id, $level, $level === ShareLevel::Manage, false, $since);

        return $this->browserFor($app, $username);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function fields(array $overrides): array
    {
        return $overrides + [
            'travelled_on' => '2026-09-30',
            'from_place' => 'Ballymena',
            'to_place' => 'Belfast',
            'is_business' => '1',
            'purpose' => 'Client meeting',
            'passengers' => '0',
        ];
    }

    /**
     * @param array<string, string> $overrides
     * @param list<UploadedFile> $files
     */
    private function logTrip(TestBrowser $browser, Vehicle $vehicle, array $overrides, array $files = []): ResponseInterface
    {
        $path = '/vehicles/' . $vehicle->id . '/trips/new';
        $browser->get($path);
        $response = $browser->post($path, self::fields($overrides), $files === [] ? [] : ['attachments' => $files]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        // Show the flash, so it never turns up on a page a test reads next.
        $browser->follow($response);

        return $response;
    }

    /**
     * Upload, preview as guessed and import a trips file; the preview or result page.
     */
    private function import(TestBrowser $browser, Vehicle $vehicle, string $csv, bool $commit = true): string
    {
        $path = '/vehicles/' . $vehicle->id . '/import/trips';
        $browser->get($path);
        $file = $this->tempFile('trips.csv', $csv);
        $upload = $browser->post($path, [], [
            'file' => new UploadedFile($file, 'trips.csv', 'text/csv', strlen($csv), UPLOAD_ERR_OK),
        ]);
        self::assertSame(303, $upload->getStatusCode(), self::body($upload));
        $map = $upload->getHeaderLine('Location');

        $mapping = self::body($browser->get($map));
        $query = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        $preview = self::body($browser->get($map . '?' . http_build_query($query)));
        if (!$commit) {
            return $preview;
        }
        $form = Html::element(Html::document($preview), 'form[method="post"][action="' . $map . '"]');
        $result = $browser->post($map, Html::formValues($form));
        self::assertSame(200, $result->getStatusCode(), self::body($result));

        return self::body($result);
    }

    private function png(string $name): UploadedFile
    {
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);

        return new UploadedFile($this->tempFile($name, $bytes), $name, 'image/png', strlen($bytes), UPLOAD_ERR_OK);
    }

    private function tempFile(string $name, string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'lbtrip');
        self::assertIsString($file);
        file_put_contents($file, $bytes);
        $this->tempFiles[] = $file;

        return $file;
    }
}
