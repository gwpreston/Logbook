<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\App;

final class VehicleTest extends AppTestCase
{
    private const array GOLF = [
        'type' => 'car',
        'make' => 'Volkswagen',
        'model' => 'Golf 1.5 TSI Life',
        'nickname' => '',
        'year' => '2019',
        'registration' => 'lb19 ktr',
        'vin' => '',
        'fuel_type' => 'petrol',
        'capacity' => '11',
        'currency' => '',
        'purchase_date' => '2021-03-14',
        'purchase_price' => '0',
        'sale_date' => '',
        'sale_price' => '',
    ];

    public function testAddShowEditDelete(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $form = $browser->get('/vehicles/new');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('enctype="multipart/form-data"', self::body($form));

        $created = $browser->post('/vehicles/new', self::GOLF);
        self::assertSame(303, $created->getStatusCode());
        self::assertMatchesRegularExpression('~^/vehicles/\d+$~', $created->getHeaderLine('Location'));
        $path = $created->getHeaderLine('Location');

        $show = self::body($browser->follow($created));
        self::assertStringContainsString('Volkswagen Golf 1.5 TSI Life was added to your garage.', $show);
        self::assertStringContainsString('<span class="plate__text">LB19 KTR</span>', $show);
        // Owner uses UK units: 11 L stays litres; zero purchase price is valid and shown.
        self::assertStringContainsString('11 L', $show);
        self::assertStringContainsString('£0.00', $show);
        self::assertStringContainsString('14 Mar 2021', $show);
        self::assertStringContainsString('GBP — British Pound', $show);

        $edit = self::body($browser->get($path . '/edit'));
        self::assertStringContainsString('value="LB19 KTR"', $edit);
        self::assertStringContainsString('value="11"', $edit);

        $changes = ['nickname' => 'The Golf', 'currency' => 'EUR', 'purchase_price' => '12500.5'];
        $updated = $browser->post($path . '/edit', $changes + self::GOLF);
        self::assertSame($path, $updated->getHeaderLine('Location'));
        $show = self::body($browser->follow($updated));
        self::assertStringContainsString('<h1 class="vehicle-hero__name">The Golf</h1>', $show);
        self::assertStringContainsString('€12,500.50', $show, 'per-vehicle currency override');

        $confirm = $browser->get($path . '/delete');
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Delete The Golf?', self::body($confirm));

        $deleted = $browser->post($path . '/delete');
        self::assertSame('/garage', $deleted->getHeaderLine('Location'));
        self::assertStringContainsString('The Golf was deleted.', self::body($browser->follow($deleted)));
        self::assertSame(404, $browser->get($path)->getStatusCode());
    }

    public function testValidationErrorsReRenderTheFormWithInput(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $invalid = ['make' => '', 'year' => '1700', 'purchase_price' => '-1', 'capacity' => 'lots'];
        $response = $browser->post('/vehicles/new', $invalid + self::GOLF);
        $html = self::body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please check the highlighted fields.', $html);
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Must be at least 1885.', $html);
        self::assertStringContainsString('Must be at least 0.', $html);
        self::assertStringContainsString('Enter a number, e.g. 1234.5.', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('value="Golf 1.5 TSI Life"', $html, 'valid input is kept');
    }

    public function testCapacityIsEnteredAndShownInTheUsersUnits(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $preset = UnitPreset::Us;
        $this->createOwner($app, 'owner', new DisplayPreferences(
            'en_US',
            'America/New_York',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'USD',
        ));
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);

        $created = $browser->post('/vehicles/new', ['capacity' => '13.207', 'purchase_price' => '1.459'] + self::GOLF);
        $vehicle = $this->onlyVehicle($app);

        self::assertSame('49.994', $vehicle->data->capacity, 'stored in litres');
        self::assertSame('1.459', $vehicle->data->purchasePrice, '3 decimals survive');

        $show = self::body($browser->follow($created));
        self::assertStringContainsString('13.21 US gal', $show);
        self::assertStringContainsString('$1.46', $show);
        self::assertStringContainsString('value="13.207"', self::body($browser->get('/vehicles/' . $vehicle->id . '/edit')));
    }

    public function testArchiveHidesFromActiveViewsButKeepsTheVehicle(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->post('/vehicles/new', self::GOLF);
        $bike = ['make' => 'Triumph', 'model' => 'Street Triple R', 'type' => 'bike', 'registration' => 'MT20 BKE'];
        $browser->post('/vehicles/new', $bike + self::GOLF);
        $golf = $this->vehicleNamed($app, 'Volkswagen Golf 1.5 TSI Life');

        $response = $browser->post('/vehicles/' . $golf->id . '/archive');
        self::assertSame('/vehicles/' . $golf->id, $response->getHeaderLine('Location'));
        $show = self::body($browser->follow($response));
        self::assertStringContainsString('was moved to the archive. Its history is kept.', $show);
        self::assertStringContainsString('This vehicle is archived', $show);

        $garage = self::body($browser->get('/garage'));
        self::assertStringNotContainsString('LB19 KTR', $garage);
        self::assertStringContainsString('MT20 BKE', $garage);
        self::assertStringContainsString('1 active vehicle · 1 archived', $garage);
        self::assertStringContainsString('Show archived (1)', $garage);

        self::assertStringNotContainsString('LB19 KTR', self::body($browser->get('/')), 'dashboard shows active only');
        self::assertStringContainsString('LB19 KTR', self::body($browser->get('/garage?archived=1')));

        // Fleet scope for totals excludes archived vehicles unless asked.
        $owner = $this->owner($app);
        $service = $this->service($app, VehicleService::class);
        self::assertCount(1, $service->listFleet($owner));
        self::assertCount(2, $service->listFleet($owner, true));

        $browser->post('/vehicles/' . $golf->id . '/restore');
        self::assertStringContainsString('LB19 KTR', self::body($browser->get('/garage')));
        self::assertNull($this->vehicleNamed($app, 'Volkswagen Golf 1.5 TSI Life')->archivedAt);
    }

    public function testGarageListsActiveFirstThenByName(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        foreach (['Zeta', 'alpha', 'Mike'] as $make) {
            $browser->post('/vehicles/new', ['make' => $make, 'model' => 'X', 'registration' => ''] + self::GOLF);
        }
        $alpha = $this->vehicleNamed($app, 'alpha X');
        $browser->post('/vehicles/' . $alpha->id . '/archive');

        $names = array_map(
            static fn ($vehicle): string => $vehicle->name(),
            $this->service($app, VehicleService::class)->listFleet($this->owner($app), true),
        );

        self::assertSame(['Mike X', 'Zeta X', 'alpha X'], $names);
    }

    public function testOtherUsersVehiclesAreInvisible(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        // A second account (the schema allows it) with its own vehicle.
        $other = $this->service($app, UserRepository::class)->insert(
            'someone',
            'x',
            'Someone Else',
            $this->owner($app)->preferences,
            $this->service($app, ClockInterface::class)->now(),
        );
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Secret', 'Car', FuelType::Diesel));

        foreach (['', '/edit', '/delete', '/photo'] as $suffix) {
            self::assertSame(404, $browser->get('/vehicles/' . $theirs->id . $suffix)->getStatusCode(), $suffix);
        }
        foreach (['/archive', '/delete', '/edit'] as $suffix) {
            self::assertSame(404, $browser->post('/vehicles/' . $theirs->id . $suffix, self::GOLF)->getStatusCode(), $suffix);
        }
        self::assertStringNotContainsString('Secret', self::body($browser->get('/garage?archived=1')));
        self::assertFalse($this->service($app, VehicleService::class)->get($other, $theirs->id)->isArchived());
    }

    public function testVehiclePagesRequireSignIn(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);

        self::assertSame('/login?next=%2Fvehicles%2F1', $browser->get('/vehicles/1')->getHeaderLine('Location'));
        self::assertSame('/login?next=%2Fvehicles%2F1%2Fphoto', $browser->get('/vehicles/1/photo')->getHeaderLine('Location'));
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

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicleNamed(App $app, string $name): Vehicle
    {
        foreach ($this->ownedVehicles($app, $this->owner($app)->id) as $vehicle) {
            if ($vehicle->name() === $name) {
                return $vehicle;
            }
        }

        self::fail('No vehicle named ' . $name);
    }
}
