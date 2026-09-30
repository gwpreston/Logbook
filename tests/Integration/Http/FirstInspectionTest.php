<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *First MOT due* (spec.md §7.1, §7.5, §7.18, §7.19, §7.20; Phase 21.2): the
 * suggestion on the add form without JS, the edit form, the read-only state
 * after the first certificate, every page that shows the date, and the
 * one-time prompt for vehicles from before 2.1.0.
 */
final class FirstInspectionTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string NOW = '2026-09-30T10:00:00Z';
    private const string PROMPT = 'Set a reminder for the first MOT?';

    /** A two-year-old car: its GB first MOT is on its third anniversary, 14 Jun 2027. */
    private const array CAR = [
        'type' => 'car',
        'make' => 'Kia',
        'model' => 'EV6',
        'fuel_type' => 'ev',
        'first_registered_on' => '2024-06-14',
        'first_inspection_due_on' => '',
        'currency' => '',
    ];

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['APP_URL' => 'http://localhost:8080']);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    // --- The form --------------------------------------------------------

    public function testTheAddFormOffersTheFieldWithTheRegionsRuleAndHint(): void
    {
        $form = self::body($this->browser->get('/vehicles/new'));

        self::assertStringContainsString('name="first_inspection_due_on"', $form);
        self::assertStringContainsString('First MOT due', $form);
        self::assertStringContainsString('data-months="36"', $form, 'GB: 3 years');
        self::assertStringContainsString('data-today="2026-09-30"', $form, 'the owner’s today, not the browser’s');
        self::assertStringContainsString('4 years in Northern Ireland', $form, 'the GB hint');
        self::assertStringContainsString('js/first-inspection.js', $form);
    }

    public function testAddingATwoYearOldCarWithoutJsSetsTheThirdAnniversaryAndSaysSo(): void
    {
        $created = $this->browser->post('/vehicles/new', self::CAR);
        self::assertSame(303, $created->getStatusCode(), self::body($created));

        self::assertSame('2027-06-14', $this->onlyVehicle()->data->firstInspectionDueOn?->format('Y-m-d'));
        $overview = self::body($this->browser->follow($created));
        self::assertStringContainsString(
            'First MOT reminder set for 14 Jun 2027. Change it on the vehicle’s edit page.',
            $overview,
        );
        self::assertStringNotContainsString(self::PROMPT, $overview, 'no prompt for a vehicle added since 2.1.0');
    }

    public function testABlankTheScriptLeftStaysBlankAndAnExplicitDateIsKept(): void
    {
        $this->browser->post('/vehicles/new', ['first_inspection_js' => '1'] + self::CAR);
        self::assertNull($this->onlyVehicle()->data->firstInspectionDueOn, 'the owner cleared it with JS on');
        self::assertStringNotContainsString(self::PROMPT, $this->overview($this->onlyVehicle()));

        $this->resetDatabase($this->app);
        $this->browser = $this->signedIn($this->app);
        $this->browser->post('/vehicles/new', ['first_inspection_due_on' => '2028-06-14'] + self::CAR);
        self::assertSame('2028-06-14', $this->onlyVehicle()->data->firstInspectionDueOn?->format('Y-m-d'), 'NI: 4 years');
    }

    public function testAVehicleWhoseFirstMotHasPassedNeverGetsASuggestion(): void
    {
        $created = $this->browser->post('/vehicles/new', ['first_registered_on' => '2021-06-14'] + self::CAR);

        self::assertNull($this->onlyVehicle()->data->firstInspectionDueOn);
        self::assertStringNotContainsString('First MOT reminder set', self::body($this->browser->follow($created)));
    }

    public function testEditingWithABlankFieldClearsItForGood(): void
    {
        $kia = $this->car('2027-06-14');
        $edit = self::body($this->browser->get('/vehicles/' . $kia->id . '/edit'));
        self::assertStringContainsString('value="2027-06-14"', $edit);

        $saved = $this->browser->post('/vehicles/' . $kia->id . '/edit', self::CAR);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        self::assertNull($this->reload($kia)->data->firstInspectionDueOn, 'a blank field stays blank on edit');
        self::assertStringNotContainsString(
            self::PROMPT,
            $this->overview($kia),
            'a deliberate clear never brings the prompt back',
        );
    }

    public function testAFirstMotBeforeFirstRegistrationIsRefused(): void
    {
        $refused = $this->browser->post('/vehicles/new', ['first_inspection_due_on' => '2024-06-13'] + self::CAR);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString(
            'The first MOT can’t be due before the vehicle was first registered.',
            self::body($refused),
        );
        self::assertSame([], $this->ownedVehicles($this->app, $this->owner($this->app)->id));
    }

    public function testOnceACertificateExistsTheFieldIsReadOnlyAndItsDateIsKept(): void
    {
        $kia = $this->car('2026-10-20');
        $this->document($this->app, $kia, ComplianceType::Inspection, '2026-10-18', '2027-10-17', '54.85');

        $edit = self::body($this->browser->get('/vehicles/' . $kia->id . '/edit'));
        self::assertStringNotContainsString('name="first_inspection_due_on"', $edit, 'not submitted');
        self::assertStringContainsString('Done: the MOT certificate from 18 Oct 2026 now sets the next one.', $edit);

        $this->browser->post('/vehicles/' . $kia->id . '/edit', ['first_inspection_due_on' => '2030-01-01'] + self::CAR);
        self::assertSame('2026-10-20', $this->reload($kia)->data->firstInspectionDueOn?->format('Y-m-d'), 'whatever is posted');
    }

    public function testWithComplianceOffTheFieldIsHiddenAndTheDateKept(): void
    {
        $kia = $this->car('2027-06-14');
        $this->complianceOff();

        self::assertStringNotContainsString('first_inspection_due_on', self::body($this->browser->get('/vehicles/new')));
        $this->browser->post('/vehicles/' . $kia->id . '/edit', self::CAR);
        self::assertSame('2027-06-14', $this->reload($kia)->data->firstInspectionDueOn?->format('Y-m-d'));
    }

    // --- Where it shows ---------------------------------------------------

    public function testTheOverviewDocumentsComingUpSalePackAndApiShowIt(): void
    {
        $kia = $this->car('2027-06-14');
        $id = (string) $kia->id;

        $overview = self::body($this->browser->get('/vehicles/' . $id));
        self::assertStringContainsString('First MOT due 14 Jun 2027', $overview);
        self::assertStringNotContainsString('No documents yet', $overview, 'not beside an empty state');
        $documents = self::body($this->browser->get('/vehicles/' . $id . '/documents'));
        self::assertStringContainsString('First MOT due 14 Jun 2027', $documents);
        self::assertStringContainsString('First MOT', self::body($this->browser->get('/upcoming')));

        $pack = self::body($this->browser->get('/vehicles/' . $id . '/sale-pack'));
        self::assertStringContainsString('First MOT due 14 Jun 2027', $pack, 'the Inspection line');

        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner($this->app)));
        self::assertSame('2027-06-14', ApiClient::json($api->get('/vehicles/' . $id))->string('first_inspection_due_on'));
        $next = ApiClient::json($api->get('/vehicles/' . $id . '/summary'))->doc('next_due');
        self::assertSame('first_inspection', $next->string('source'));
        self::assertSame('First MOT', $next->string('name'));
    }

    public function testAfterTheFirstCertificateOnlyTheCertificateShows(): void
    {
        $kia = $this->car('2026-10-20');
        $this->document($this->app, $kia, ComplianceType::Inspection, '2026-10-18', '2027-10-17', '54.85');
        $id = (string) $kia->id;

        $overview = self::body($this->browser->get('/vehicles/' . $id));
        self::assertStringNotContainsString('First MOT due', $overview);
        self::assertStringNotContainsString('First MOT', self::body($this->browser->get('/upcoming')));
        $pack = self::body($this->browser->get('/vehicles/' . $id . '/sale-pack'));
        self::assertStringNotContainsString('First MOT due', $pack);
        self::assertStringContainsString('MOT valid until 17 Oct 2027', $pack);
    }

    // --- The one-time prompt (option A) -----------------------------------

    public function testAVehicleFromBefore21IsOfferedTheSuggestionAndSetItStoresIt(): void
    {
        $kia = $this->car(null);
        $overview = $this->overview($kia);
        self::assertStringContainsString(self::PROMPT, $overview);
        self::assertStringContainsString('Suggested: 14 Jun 2027.', $overview);

        $set = $this->browser->post('/vehicles/' . $kia->id . '/first-inspection', ['choice' => 'set', 'on' => '2030-01-01']);
        self::assertSame(303, $set->getStatusCode());
        $stored = $this->reload($kia)->data->firstInspectionDueOn;
        self::assertSame('2027-06-14', $stored?->format('Y-m-d'), 'worked out again: never the posted date');
        $after = self::body($this->browser->follow($set));
        self::assertStringContainsString('First MOT reminder set for 14 Jun 2027.', $after);
        self::assertStringNotContainsString(self::PROMPT, $after);
    }

    public function testNotNeededSetsNothingAndNeverReturns(): void
    {
        $kia = $this->car(null);
        $this->browser->get('/vehicles/' . $kia->id);
        $this->browser->post('/vehicles/' . $kia->id . '/first-inspection', ['choice' => 'dismiss']);

        self::assertNull($this->reload($kia)->data->firstInspectionDueOn);
        self::assertStringNotContainsString(self::PROMPT, $this->overview($kia));
        $this->pinClock($this->app, '2027-01-01T10:00:00Z');
        self::assertStringNotContainsString(self::PROMPT, $this->overview($kia), 'not later either');
    }

    public function testThePromptShowsOnlyWhenItShould(): void
    {
        $owner = $this->owner($this->app);
        $noRegistration = $this->car(null, registered: null);
        $past = $this->car(null, registered: '2021-06-14', model: 'Niro');
        $tested = $this->car(null, model: 'Soul');
        $this->document($this->app, $tested, ComplianceType::Inspection, '2026-06-01', '2027-05-31', '54.85');
        $archived = $this->car(null, model: 'Rio');
        $this->service($this->app, VehicleService::class)->archive($owner, $archived);
        $shown = $this->car(null, model: 'Ceed');

        $hidden = [
            'no first registration' => $noRegistration,
            'first MOT passed' => $past,
            'a certificate' => $tested,
            'archived' => $archived,
        ];
        foreach ($hidden as $why => $vehicle) {
            self::assertStringNotContainsString(self::PROMPT, $this->overview($vehicle), $why);
        }
        self::assertStringContainsString(self::PROMPT, $this->overview($shown));

        $viewer = $this->createMember($this->app);
        $this->share($shown, $viewer, ShareLevel::View);
        $theirs = $this->browserFor($this->app, 'partner');
        self::assertStringNotContainsString(self::PROMPT, $this->overview($shown, $theirs), 'a viewer cannot set it');
        $refused = $theirs->post('/vehicles/' . $shown->id . '/first-inspection', ['choice' => 'set']);
        self::assertSame(403, $refused->getStatusCode());

        $this->complianceOff();
        self::assertStringNotContainsString(self::PROMPT, $this->overview($shown), 'compliance off');
    }

    public function testAManagerSeesTheOwnersSuggestionAndSettlingItSettlesItForBoth(): void
    {
        $kia = $this->car(null);
        $manager = $this->createMember($this->app, 'partner', new DisplayPreferences(
            'en_US',
            'America/New_York',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'USD',
        ));
        $this->share($kia, $manager, ShareLevel::Manage);
        $theirs = $this->browserFor($this->app, 'partner');

        $overview = $this->overview($kia, $theirs);
        self::assertStringContainsString(self::PROMPT, $overview, 'the owner’s GB rule, not the manager’s US locale');
        $theirs->post('/vehicles/' . $kia->id . '/first-inspection', ['choice' => 'dismiss']);
        self::assertStringNotContainsString(self::PROMPT, $this->overview($kia));
    }

    private function car(?string $firstMot, ?string $registered = '2024-06-14', string $model = 'EV6'): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create($this->owner($this->app), new VehicleData(
            VehicleType::Car,
            'Kia',
            $model,
            FuelType::Electric,
            firstRegisteredOn: $registered === null ? null : LocalTime::parseDate($registered),
            firstInspectionDueOn: $firstMot === null ? null : LocalTime::parseDate($firstMot),
        ));
    }

    private function share(Vehicle $vehicle, User $user, ShareLevel $level): void
    {
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($vehicle->id, $user->id, $level, false, false, new DateTimeImmutable(self::NOW));
    }

    private function complianceOff(): void
    {
        $this->service($this->app, FeatureToggles::class)
            ->save([Feature::Fuel, Feature::Maintenance, Feature::Reminders, Feature::Reports, Feature::Tyres]);
    }

    private function overview(Vehicle $vehicle, ?TestBrowser $browser = null): string
    {
        return self::body(($browser ?? $this->browser)->get('/vehicles/' . $vehicle->id));
    }

    private function reload(Vehicle $vehicle): Vehicle
    {
        $fresh = $this->service($this->app, VehicleRepository::class)->findById($vehicle->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function onlyVehicle(): Vehicle
    {
        $vehicles = $this->ownedVehicles($this->app, $this->owner($this->app)->id);
        self::assertCount(1, $vehicles);

        return $vehicles[0];
    }
}
