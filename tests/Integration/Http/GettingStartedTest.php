<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\GettingStarted\StartStep;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\MaintenanceScheduleRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\GettingStarted\GettingStartedProgress;
use Logbook\Service\GettingStarted\GettingStartedService;
use Logbook\Service\GettingStarted\GettingStartedStore;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Http\Message\ResponseInterface;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Set up your first vehicle* (Phase 49.1, spec.md §7.9): each step saved
 * through the real services, each skippable, resumable, and the card on the
 * overview.
 */
final class GettingStartedTest extends AppTestCase
{
    private const string NOW = '2026-10-14T10:00:00Z';
    private const string START = '/vehicles/start';

    public function testEmptyGarageOffersTheFlow(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $garage = self::body($browser->get('/garage'));

        self::assertStringContainsString('href="' . self::START . '"', $garage);
        self::assertStringContainsString('Set up your first vehicle', $garage);
    }

    public function testWholeFlowSavesEachStepThroughTheServices(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $first = self::body($browser->get(self::START));
        self::assertStringContainsString('Step 1 of 5', $first);

        $made = $browser->post(self::START, [
            'step' => 'vehicle', 'type' => 'car', 'registration' => 'ab12 cde', 'make' => 'Ford', 'model' => 'Fiesta',
            'fuel_type' => 'petrol', 'first_registered_on' => '',
        ]);
        self::assertSame(303, $made->getStatusCode());
        $vehicle = $this->onlyVehicle($app);
        self::assertSame('AB12 CDE', $vehicle->data->registration);
        self::assertNull($vehicle->data->firstInspectionDueOn, 'no first MOT date is suggested in the flow');

        self::assertStringContainsString('Step 2 of 5', self::body($browser->get(self::START)));
        $browser->post(self::START, ['step' => 'mileage', 'reading' => '45200']);
        $latest = $this->service($app, OdometerService::class)->history($vehicle)->latest();
        $km = DistanceUnit::Mile->toKmDecimal('45200', 3);
        self::assertSame($km, $latest?->readingKm, 'typed in miles, stored in km');

        self::assertStringContainsString('Step 3 of 5', self::body($browser->get(self::START)));
        $browser->post(self::START, [
            'step' => 'dates', 'mot_due_on' => '2027-03-01', 'tax_due_on' => '2027-02-01',
            'insurance_due_on' => '2027-06-30', 'insurer' => 'Acme',
        ]);
        $documents = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(3, $documents);
        [$mot, $tax, $insurance] = array_map(static fn (ComplianceDocument $d): ComplianceDocumentData => $d->data, $documents);
        self::assertSame(ComplianceType::Inspection, $mot->type);
        self::assertSame('2027-03-01', $mot->expiryOn?->format('Y-m-d'));
        self::assertSame('Vehicle tax', $tax->title);
        self::assertSame(ComplianceType::Insurance, $insurance->type);
        self::assertSame('Acme', $insurance->provider);

        $servicing = self::body($browser->get(self::START));
        self::assertStringContainsString('Step 4 of 5', $servicing);
        self::assertStringContainsString('value="10000"', $servicing, 'the preset in miles for a UK owner');
        self::assertStringContainsString('value="12"', $servicing);
        $browser->post(self::START, ['step' => 'servicing', 'interval_distance' => '10000', 'interval_months' => '12']);
        $schedules = $this->service($app, MaintenanceScheduleRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(1, $schedules);
        self::assertSame('2026-10-14', $schedules[0]->data->baselineDoneOn?->format('Y-m-d'), 'no purchase date: today');
        self::assertSame($km, $schedules[0]->data->baselineDoneKm, 'the mileage now, for today');

        $done = self::body($browser->get(self::START));
        self::assertStringContainsString('Step 5 of 5', $done);
        self::assertStringContainsString('/vehicles/' . $vehicle->id . '/fuel/new', $done);
        self::assertStringContainsString('/vehicles/' . $vehicle->id . '/sharing', $done);
        self::assertStringContainsString('/vehicles/' . $vehicle->id . '/edit', $done);

        $overview = self::body($browser->get('/vehicles/' . $vehicle->id));
        self::assertStringNotContainsString('Getting started', $overview, 'complete: no card');
    }

    public function testEveryStepCanBeSkippedAndTheCardLinksBack(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->makeVehicle($browser, 'bike', 'Honda', 'CB');
        $vehicle = $this->onlyVehicle($app);

        foreach (['mileage', 'dates', 'servicing'] as $step) {
            $browser->post(self::START, ['step' => $step, 'skip' => '1']);
        }
        self::assertSame([], $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($vehicle->id));
        self::assertSame([], $this->service($app, MaintenanceScheduleRepository::class)->listForVehicle($vehicle->id));

        self::assertStringContainsString('Step 5 of 5', self::body($browser->get(self::START)));
        $overview = self::body($browser->get('/vehicles/' . $vehicle->id));
        self::assertStringContainsString('Getting started', $overview);
        self::assertStringContainsString('2 of 5 done', $overview);
        self::assertStringContainsString('href="' . self::START . '?step=mileage"', $overview);

        // A skipped step reopens from the card.
        self::assertStringContainsString('Step 2 of 5', self::body($browser->get(self::START . '?step=mileage')));
        $browser->post(self::START, ['step' => 'mileage', 'reading' => '0']);
        $latest = $this->service($app, OdometerService::class)->history($vehicle)->latest();
        self::assertSame('0.000', $latest?->readingKm, '0 is valid');
    }

    public function testSkippingTheFirstStepDismissesTheFlow(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $skipped = $browser->post(self::START, ['step' => 'vehicle', 'skip' => '1']);

        self::assertSame('/garage', $skipped->getHeaderLine('Location'));
        $owner = $this->owner($app);
        self::assertTrue($this->service($app, GettingStartedStore::class)->load($owner->id)->dismissed);
        self::assertFalse($this->service($app, GettingStartedService::class)->wanted($owner));
    }

    public function testNotSavedWhenTheVehicleIsInvalid(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $response = $this->makeVehicle($browser, 'car', '', '');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->ownedVehicles($app, $this->owner($app)->id));
    }

    public function testMileageErrorsStayOnTheStep(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->makeVehicle($browser, 'car', 'Ford', 'Ka');

        $response = $browser->post(self::START, ['step' => 'mileage', 'reading' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Step 2 of 5', self::body($browser->get(self::START)));
    }

    public function testResumesAfterSignOutAndComesBackToStepOneWhenTheVehicleIsGone(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->makeVehicle($browser, 'car', 'Ford', 'Ka');
        $vehicle = $this->onlyVehicle($app);
        $browser->post(self::START, ['step' => 'mileage', 'skip' => '1']);

        $again = $this->browserFor($app, 'owner');
        self::assertStringContainsString('Step 3 of 5', self::body($again->get(self::START)));

        $this->service($app, VehicleService::class)->delete($this->owner($app), $vehicle);
        self::assertStringContainsString('Step 1 of 5', self::body($again->get(self::START)));
    }

    public function testModulesSwitchedOffTakeTheirStepsOut(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $off = ['compliance' => false, 'maintenance' => false, 'fuel' => false];
        $this->service($app, SettingRepository::class)->save('features', $off);
        $this->makeVehicle($browser, 'car', 'Ford', 'Ka');
        $vehicle = $this->onlyVehicle($app);
        $browser->post(self::START, ['step' => 'mileage', 'reading' => '10']);

        $page = self::body($browser->get(self::START));

        self::assertStringContainsString('Step 3 of 3', $page);
        self::assertStringNotContainsString('/fuel/new', $page);
        $steps = $this->service($app, GettingStartedService::class)->steps();
        self::assertSame([StartStep::Vehicle, StartStep::Mileage, StartStep::Done], $steps);
        self::assertSame($vehicle->id, $this->service($app, GettingStartedStore::class)->load($this->owner($app)->id)->vehicleId);
    }

    public function testDismissHidesTheCardAndKeepsWhatWasSaved(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->makeVehicle($browser, 'car', 'Ford', 'Ka');
        $vehicle = $this->onlyVehicle($app);
        $overview = self::body($browser->get('/vehicles/' . $vehicle->id));
        self::assertStringContainsString('1 of 5 done', $overview);
        self::assertStringContainsString('action="' . self::START . '/dismiss"', $overview);

        $browser->post(self::START . '/dismiss', []);

        self::assertStringNotContainsString('Getting started', self::body($browser->get('/vehicles/' . $vehicle->id)));
        self::assertCount(1, $this->ownedVehicles($app, $this->owner($app)->id));
    }

    public function testAnotherUsersVehicleIsNeverTheFlowsVehicle(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $this->makeVehicle($browser, 'car', 'Ford', 'Ka');
        $vehicle = $this->onlyVehicle($app);
        $service = $this->service($app, GettingStartedService::class);
        $member = $this->createMember($app, 'second');

        $this->service($app, GettingStartedStore::class)->save($member->id, new GettingStartedProgress($vehicle->id));

        self::assertNull($service->vehicle($member, $service->progress($member)));
        self::assertNull($service->card($member, $vehicle));
        self::assertSame($owner->id, $this->owner($app)->id);
    }

    public function testServicingPresetIsSixteenThousandKmForMetricUsers(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $metric = new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::LitresPer100Km,
            'EUR',
        );
        $user = $this->createOwner($app, 'owner', $metric);
        $service = $this->service($app, GettingStartedService::class);

        self::assertSame(['interval_distance' => '16000', 'interval_months' => '12'], $service->servicingDefaults($user));
    }

    private function makeVehicle(TestBrowser $browser, string $type, string $make, string $model): ResponseInterface
    {
        return $browser->post(self::START, [
            'step' => 'vehicle', 'type' => $type, 'make' => $make, 'model' => $model, 'fuel_type' => 'petrol',
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function owner(App $app): User
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyVehicle(App $app): Vehicle
    {
        $vehicles = $this->ownedVehicles($app, $this->owner($app)->id);
        self::assertCount(1, $vehicles);

        return $vehicles[0];
    }
}
