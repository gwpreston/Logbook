<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Vehicle\PurchaseMileageNeedsDate;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\View\View;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Bought from* and *Mileage when bought* (Phase 33.3, #182, spec.md §6,
 * §7.1, §7.2): the seller on the vehicle, the mileage as its single
 * `purchase` reading at local noon on the purchase date, written, moved and
 * removed with the vehicle form (page and modal, add and edit), refused
 * without the date, shown on the Ownership card, labelled on the Mileage
 * tab and in the API.
 */
final class PurchaseMileageTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string NOW = '2026-10-05T10:00:00Z';

    private const array GOLF = [
        'type' => 'car',
        'make' => 'Volkswagen',
        'model' => 'Golf',
        'fuel_type' => 'petrol',
        'currency' => '',
        'purchase_date' => '2021-05-01',
        'purchase_price' => '12500',
        'purchase_seller' => '  Halden Motors ',
        'purchase_odometer' => '30000',
    ];

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testAddingWritesThePurchaseReadingAtLocalNoonAndTheSeller(): void
    {
        [$app, $browser] = $this->app();
        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('name="purchase_seller"', $form);
        self::assertStringContainsString('name="purchase_odometer"', $form);
        self::assertStringContainsString('Mileage when bought', $form);

        $created = $browser->post('/vehicles/new', self::GOLF);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        $golf = $this->onlyVehicle($app);
        self::assertSame('Halden Motors', $golf->data->purchaseSeller, 'trimmed');

        $reading = $this->purchaseReading($app, $golf);
        self::assertNotNull($reading);
        // 30,000 mi (the owner's unit) in km, at noon in London (BST) on the purchase date.
        self::assertSame('48280.320', $reading->readingKm);
        self::assertSame('2021-05-01T11:00:00+00:00', $reading->recordedAt->format('c'));
        self::assertNull($reading->createdBy, 'owned by the vehicle, not authored');

        // The edit form shows both.
        $edit = self::body($browser->get('/vehicles/' . $golf->id . '/edit'));
        self::assertStringContainsString('value="Halden Motors"', $edit);
        self::assertMatchesRegularExpression('/name="purchase_odometer" type="number" value="30000"/', $edit);
    }

    public function testEditingMovesChangesAndRemovesTheReadingAsAPageAndInTheModal(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $edit = '/vehicles/' . $golf->id . '/edit';

        // A new purchase date moves it; the modal answers 204.
        $moved = $browser->post($edit, ['purchase_date' => '2021-12-01'] + self::GOLF, headers: [View::MODAL_HEADER => '1']);
        self::assertSame(204, $moved->getStatusCode(), self::body($moved));
        $reading = $this->purchaseReading($app, $golf);
        self::assertNotNull($reading);
        self::assertSame('2021-12-01T12:00:00+00:00', $reading->recordedAt->format('c'), 'noon GMT in winter');
        $id = $reading->id;

        // A new figure changes the same reading; 0 is valid.
        $browser->post($edit, ['purchase_odometer' => '0'] + self::GOLF);
        $reading = $this->purchaseReading($app, $golf);
        self::assertNotNull($reading);
        self::assertSame($id, $reading->id, 'one reading, moved');
        self::assertSame('0.000', $reading->readingKm);

        // Blank removes it; the seller blank is null.
        $removed = $browser->post($edit, ['purchase_odometer' => '', 'purchase_seller' => ''] + self::GOLF);
        self::assertSame(303, $removed->getStatusCode(), self::body($removed));
        self::assertNull($this->purchaseReading($app, $golf));
        self::assertNull($this->fresh($app, $golf)->data->purchaseSeller);
        self::assertCount(0, $this->readings($app, $golf));
    }

    public function testTheMileageNeedsThePurchaseDate(): void
    {
        [$app, $browser] = $this->app();

        $refused = $browser->post('/vehicles/new', ['purchase_date' => ''] + self::GOLF);
        self::assertSame(422, $refused->getStatusCode());
        $html = self::body($refused);
        self::assertStringContainsString('Add the purchase date to record the mileage when bought.', $html);
        self::assertStringContainsString('value="Volkswagen"', $html, 'typed values are kept');
        self::assertSame([], $this->ownedVehicles($app, $this->owner($app)->id), 'nothing written');

        // The same on edit for a vehicle with no reading yet.
        $browser->post('/vehicles/new', ['purchase_date' => '', 'purchase_odometer' => ''] + self::GOLF);
        $golf = $this->onlyVehicle($app);
        $edit = $browser->post('/vehicles/' . $golf->id . '/edit', ['purchase_date' => ''] + self::GOLF);
        self::assertSame(422, $edit->getStatusCode());
        self::assertStringContainsString('Add the purchase date to record the mileage when bought.', self::body($edit));
        self::assertNull($this->purchaseReading($app, $golf));
    }

    public function testClearingThePurchaseDateWhileTheMileageIsSetIsRefused(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $edit = '/vehicles/' . $golf->id . '/edit';

        $refused = $browser->post($edit, ['purchase_date' => '', 'model' => 'Golf GTI'] + self::GOLF);
        self::assertSame(422, $refused->getStatusCode());
        $html = self::body($refused);
        self::assertStringContainsString('Remove the mileage when bought first, or keep the purchase date.', $html);
        self::assertStringContainsString('value="Golf GTI"', $html, 'typed values are kept');
        self::assertSame('Golf', $this->fresh($app, $golf)->data->model, 'nothing written');
        self::assertNotNull($this->purchaseReading($app, $golf));

        // Clearing both is fine: the reading goes with the date.
        $cleared = $browser->post($edit, ['purchase_date' => '', 'purchase_odometer' => ''] + self::GOLF);
        self::assertSame(303, $cleared->getStatusCode(), self::body($cleared));
        self::assertNull($this->purchaseReading($app, $golf));
    }

    public function testASaveOutsideTheFormKeepsAndMovesTheReading(): void
    {
        [$app, , $golf] = $this->golf();
        $vehicles = $this->service($app, VehicleService::class);
        $owner = $this->owner($app);
        $data = $this->fresh($app, $golf)->data;

        // e.g. the first MOT prompt or a scan: no PurchaseMileage given.
        $vehicles->update($owner, $golf, new VehicleData(
            $data->type,
            $data->make,
            $data->model,
            $data->fuelType,
            purchaseDate: LocalTime::parseDate('2022-03-10'),
            purchaseSeller: $data->purchaseSeller,
        ));
        $reading = $this->purchaseReading($app, $golf);
        self::assertNotNull($reading);
        self::assertSame('48280.320', $reading->readingKm, 'kept');
        self::assertSame('2022-03-10T12:00:00+00:00', $reading->recordedAt->format('c'), 'moved');

        $this->expectException(PurchaseMileageNeedsDate::class);
        $vehicles->update($owner, $golf, new VehicleData($data->type, $data->make, $data->model, $data->fuelType));
    }

    public function testAnImplausibleMileageIsSavedWithTheWarning(): void
    {
        [$app, $browser] = $this->app();
        // 40,000 mi read in 2020, then bought at 30,000 mi in 2021.
        $browser->post('/vehicles/new', [
            'current_odometer' => '40000',
            'current_odometer_on' => '2020-01-15',
        ] + self::GOLF);
        $page = self::body($browser->get('/garage'));
        self::assertStringContainsString('Saved, but this reading is lower than the one before it', $page);
        self::assertNotNull($this->purchaseReading($app, $this->onlyVehicle($app)), 'never blocked');
    }

    public function testTheOwnershipCardAndTheMileageTabShowIt(): void
    {
        [$app, $browser, $golf] = $this->golf();

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('From Halden Motors', $overview);
        self::assertStringContainsString('30,000 mi when bought', $overview);

        $mileage = self::body($browser->get('/vehicles/' . $golf->id . '/odometer'));
        self::assertStringContainsString('<span class="pill">Bought</span>', $mileage);
        $reading = $this->purchaseReading($app, $golf);
        self::assertNotNull($reading);

        // Its edit link opens the vehicle form; it is never edited or deleted as a manual reading.
        $link = '/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/edit';
        self::assertStringContainsString('href="' . $link . '"', $mileage);
        $open = $browser->get($link);
        self::assertSame(303, $open->getStatusCode());
        self::assertSame('/vehicles/' . $golf->id . '/edit', $open->getHeaderLine('Location'));
        $delete = $browser->get('/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/delete');
        self::assertSame(404, $delete->getStatusCode());
    }

    public function testTheApiCarriesTheSellerAndThePurchaseSource(): void
    {
        [$app, , $golf] = $this->golf();
        $api = $this->api($app, $this->apiKey($app, $this->owner($app)));

        $vehicle = ApiClient::json($api->get('/vehicles/' . $golf->id));
        self::assertSame('Halden Motors', $vehicle->get('purchase_seller'));

        $readings = ApiClient::json($api->get('/vehicles/' . $golf->id . '/odometer'))->doc('items');
        self::assertSame('purchase', $readings->get(0, 'source'));
        self::assertNull($readings->get(0, 'source_id'));
        self::assertSame('48280.320', $readings->get(0, 'odometer'));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser}
     */
    private function app(): array
    {
        $app = $this->createApp(['APP_URL' => 'http://localhost:8080']);
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }

    /**
     * The app with the Golf added through the form.
     *
     * @return array{0: App<ContainerInterface>, 1: TestBrowser, 2: Vehicle}
     */
    private function golf(): array
    {
        [$app, $browser] = $this->app();
        $created = $browser->post('/vehicles/new', self::GOLF);
        self::assertSame(303, $created->getStatusCode(), self::body($created));

        return [$app, $browser, $this->onlyVehicle($app)];
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

    /**
     * @param App<ContainerInterface> $app
     */
    private function fresh(App $app, Vehicle $vehicle): Vehicle
    {
        return $this->service($app, VehicleService::class)->get($this->owner($app), $vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function purchaseReading(App $app, Vehicle $vehicle): ?OdometerReading
    {
        $found = array_values(array_filter(
            $this->readings($app, $vehicle),
            static fn (OdometerReading $r): bool => $r->source === OdometerSource::Purchase,
        ));
        self::assertLessThanOrEqual(1, count($found), 'at most one per vehicle');

        return $found[0] ?? null;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<OdometerReading>
     */
    private function readings(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, OdometerReadingRepository::class)->listForVehicle($vehicle->id);
    }
}
