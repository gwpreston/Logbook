<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Fill-ups end to end: the form, the odometer reading each one writes, the
 * derived figures on the list, and the fast "Log" path. The owner uses UK
 * units (miles, litres, mpg UK, GBP, Europe/London).
 */
final class FuelTest extends AppTestCase
{
    private const array FILL = [
        'filled_at' => '2026-07-01T09:15',
        'odometer' => '10000',
        'fuel' => 'petrol',
        'volume' => '40',
        'price' => '1.5',
        'total' => '',
        'station' => '',
        'notes' => '',
    ];

    public function testLogEditAndDeleteKeepTheOdometerInStep(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/fuel';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('name="filled_at"', $form);
        self::assertStringContainsString('value="petrol" selected', $form);
        self::assertStringContainsString('Fill in any two; the third is worked out.', $form);

        $created = $browser->post($base . '/new', self::FILL);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame($base, $created->getHeaderLine('Location'));
        self::assertStringContainsString('Fill-up saved.', self::body($browser->follow($created)));

        $first = $this->entries($app, $golf)[0];
        self::assertSame('16093.440', $first->data->odometerKm, '10,000 mi in km');
        self::assertSame('40.000', $first->data->volume);
        self::assertSame('60.000', $first->data->totalCost, 'derived: 40 L × £1.50');
        self::assertSame('2026-07-01 08:15:00', $first->data->filledAt->format('Y-m-d H:i:s'), '09:15 BST stored as UTC');

        $reading = $this->service($app, OdometerReadingRepository::class)->findByFuelEntry($golf->id, $first->id);
        self::assertNotNull($reading, 'a fill-up writes its odometer reading');
        self::assertSame(OdometerSource::Fuel, $reading->source);
        self::assertSame('16093.440', $reading->readingKm);
        self::assertEquals($first->data->filledAt, $reading->recordedAt);

        // A partial top-up, then a full fill: 400 mi on 20 + 22 L.
        $partial = ['volume' => '20', 'price' => '', 'total' => '30', 'partial' => '1'];
        $browser->post($base . '/new', self::fill('2026-07-05T18:00', '10200', $partial));
        $third = $browser->post($base . '/new', self::fill('2026-07-10T08:00', '10400', ['volume' => '22']));
        // 42 L = 9.239 UK gal; 400 / 9.239 = 43.3 mpg.
        self::assertStringContainsString('Fill-up saved: 43.3 mpg since the last full tank.', self::body(
            $browser->follow($third),
        ));

        $list = self::body($browser->get($base));
        self::assertStringContainsString('43.3 mpg', $list);
        self::assertStringContainsString('Partial fill', $list);
        self::assertStringContainsString('Full · starting point', $list);
        self::assertStringContainsString('£1.50/L', $list);
        self::assertStringContainsString('£123.00', $list, 'spent on fuel');
        self::assertStringContainsString('data-chart=', $list);
        self::assertStringContainsString('mpg (US)', $list, 'the average in the other units');

        // Editing moves the reading and recomputes everything: now 500 mi.
        $last = $this->entries($app, $golf)[2];
        $editPath = $base . '/' . $last->id . '/edit';
        $edit = self::body($browser->get($editPath));
        self::assertStringContainsString('value="10400"', $edit);
        self::assertStringContainsString('value="22"', $edit);
        self::assertStringContainsString('value="33"', $edit, 'the derived total is stored and shown');
        self::assertStringContainsString('value="2026-07-10T08:00"', $edit, 'shown in the owner’s time zone');

        $changes = ['volume' => '22', 'price' => '', 'total' => '33'];
        $updated = $browser->post($editPath, self::fill('2026-07-10T08:00', '10500', $changes));
        self::assertSame($base, $updated->getHeaderLine('Location'));
        $html = self::body($browser->follow($updated));
        self::assertStringContainsString('Fill-up updated: 54.1 mpg since the previous full tank.', $html);
        $moved = $this->service($app, OdometerReadingRepository::class)->findByFuelEntry($golf->id, $last->id);
        self::assertSame('16898.112', $moved?->readingKm);

        // Deleting removes the fill-up and its reading.
        $confirm = $browser->get($base . '/' . $last->id . '/delete');
        self::assertSame(200, $confirm->getStatusCode());
        self::assertStringContainsString('Delete this fill-up?', self::body($confirm));
        $deleted = $browser->post($base . '/' . $last->id . '/delete');
        self::assertSame($base, $deleted->getHeaderLine('Location'));
        self::assertStringContainsString('was deleted', self::body($browser->follow($deleted)));
        self::assertCount(2, $this->entries($app, $golf));
        self::assertCount(2, $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id));
        self::assertSame(404, $browser->get($editPath)->getStatusCode());
    }

    public function testValidationErrorsReRenderTheFormWithInput(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->get('/vehicles/' . $golf->id . '/fuel/new');

        $response = $browser->post(
            '/vehicles/' . $golf->id . '/fuel/new',
            ['odometer' => '', 'price' => '', 'station' => 'Shell'] + self::FILL,
        );
        $html = self::body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please check the highlighted fields.', $html);
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Enter at least two of volume, price and total.', $html);
        self::assertStringContainsString('value="Shell"', $html, 'input is kept');
        self::assertStringContainsString('<details class="card disclosure" open>', $html, 'filled details stay open');
        self::assertSame([], $this->entries($app, $golf));
    }

    public function testAMissedFillUpIsShownAndKeptOutOfTheAverage(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/fuel/new';

        $browser->post($base, ['filled_at' => '2026-07-01T09:00', 'odometer' => '10000'] + self::FILL);
        $browser->post($base, ['filled_at' => '2026-07-10T09:00', 'odometer' => '10400', 'volume' => '40'] + self::FILL);
        // One fill-up was never logged; without the flag this would read 800 mi on 40 L.
        $gap = $browser->post($base, self::fill('2026-07-30T09:00', '11200', ['missed_previous' => '1']));

        $html = self::body($browser->follow($gap));
        self::assertStringContainsString('Fill-up saved.', $html);
        self::assertStringContainsString('After a missed fill-up: measuring restarts here', $html);
        // The only measured stretch: 400 mi on 40 L = 45.5 mpg (UK).
        self::assertStringContainsString('45.5 mpg', $html);
        self::assertStringNotContainsString('90.9 mpg', $html);
    }

    public function testElectricChargesUseKwhAndEfficiency(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $ev = $this->vehicle($app, FuelType::Electric);
        $base = '/vehicles/' . $ev->id . '/fuel';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('value="ev" selected', $form);
        self::assertStringContainsString('kWh', $form);

        $charge = ['fuel' => 'ev', 'volume' => '60', 'price' => '0.25'] + self::FILL;
        $browser->post($base . '/new', $charge);
        $saved = $browser->post($base . '/new', ['filled_at' => '2026-07-08T09:15', 'odometer' => '10186.4'] + $charge);

        // 186.4 mi on 60 kWh = 3.1 mi/kWh.
        $html = self::body($browser->follow($saved));
        self::assertStringContainsString('Fill-up saved: 3.1 mi/kWh since the last full tank.', $html);
        $html = self::body($browser->get($base));
        self::assertStringContainsString('60 kWh', $html);
        self::assertStringContainsString('£0.25/kWh', $html);
        self::assertStringContainsString('Average efficiency', $html);
        self::assertStringContainsString('Spent on charging', $html);
        self::assertSame('60.000', $this->entries($app, $ev)[1]->data->volume, 'kWh stored as-is');
    }

    public function testQuickLogPath(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $none = $browser->get('/fuel/new');
        self::assertSame('/vehicles/new', $none->getHeaderLine('Location'));
        self::assertStringContainsString('Add a vehicle first', self::body($browser->follow($none)));

        $golf = $this->vehicle($app);
        self::assertSame('/vehicles/' . $golf->id . '/fuel/new', $browser->get('/fuel/new')->getHeaderLine('Location'));

        $bike = $this->vehicle($app, FuelType::Petrol, 'Triumph', 'Street Triple');
        $sold = $this->vehicle($app, FuelType::Diesel, 'Ford', 'Mondeo');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $sold);

        $picker = $browser->get('/fuel/new');
        $html = self::body($picker);
        self::assertSame(200, $picker->getStatusCode());
        self::assertStringContainsString('Which vehicle?', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel/new"', $html);
        self::assertStringContainsString('href="/vehicles/' . $bike->id . '/fuel/new"', $html);
        self::assertStringNotContainsString('Mondeo', $html, 'archived vehicles are not offered');

        // The Log entry button ("+", opening the chooser) is in the navigation of every signed-in page.
        self::assertStringContainsString('bottom-nav__link--fab" href="/log/new"', self::body($browser->get('/garage')));
    }

    public function testFillUpsOfOtherVehiclesAndUsersAreUnreachable(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, FuelType::Petrol, 'Triumph', 'Street Triple');
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::FILL);
        $entry = $this->entries($app, $golf)[0];

        // The right entry id under another vehicle of the same owner.
        self::assertSame(404, $browser->get('/vehicles/' . $bike->id . '/fuel/' . $entry->id . '/edit')->getStatusCode());

        // Another account's vehicle.
        $other = $this->service($app, UserRepository::class)->insert(
            'someone',
            'x',
            'Someone Else',
            $this->owner($app)->preferences,
            $this->service($app, ClockInterface::class)->now(),
        );
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Secret', 'Car', FuelType::Diesel));
        foreach (['/fuel', '/fuel/new', '/odometer'] as $suffix) {
            self::assertSame(404, $browser->get('/vehicles/' . $theirs->id . $suffix)->getStatusCode(), $suffix);
        }
        self::assertSame(404, $browser->post('/vehicles/' . $theirs->id . '/fuel/new', self::FILL)->getStatusCode());
    }

    public function testDeletingTheVehicleDeletesItsHistory(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::FILL);
        $reading = ['reading' => '10100', 'recorded_at' => '2026-07-03T10:00', 'note' => ''];
        $browser->post('/vehicles/' . $golf->id . '/odometer/new', $reading);

        $browser->post('/vehicles/' . $golf->id . '/delete');

        $connection = $this->connection($app);
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM fuel_entries'));
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
    }

    public function testUsUnitsRoundTripThroughTheForm(): void
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
        $car = $this->vehicle($app);

        $fill = self::fill('2026-07-01T09:15', '12345.6', ['volume' => '13.207', 'price' => '3.499']);
        $browser->post('/vehicles/' . $car->id . '/fuel/new', $fill);
        $entry = $this->entries($app, $car)[0];
        self::assertSame('49.994', $entry->data->volume);
        self::assertSame('46.210', $entry->data->totalCost);

        $edit = self::body($browser->get('/vehicles/' . $car->id . '/fuel/' . $entry->id . '/edit'));
        self::assertStringContainsString('value="12345.6"', $edit);
        self::assertStringContainsString('value="13.207"', $edit);
        self::assertStringContainsString('value="3.499"', $edit);
        self::assertStringContainsString('value="46.21"', $edit);
        self::assertStringContainsString('$3.499/US gal', self::body($browser->get('/vehicles/' . $car->id . '/fuel')));
    }

    public function testFuelPagesWorkAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/logbook/login');
        $browser->post('/logbook/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $golf = $this->vehicle($app);

        $created = $browser->post('/logbook/vehicles/' . $golf->id . '/fuel/new', self::FILL);
        self::assertSame('/logbook/vehicles/' . $golf->id . '/fuel', $created->getHeaderLine('Location'));

        // Hard refresh with the prefix stripped by the proxy.
        $page = $browser->get('/vehicles/' . $golf->id . '/fuel');
        $html = self::body($page);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/odometer"', $html);
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/fuel/', $html);
        self::assertStringContainsString('href="/logbook/log/new"', $html);
    }

    /**
     * The standard fill (40 L at £1.50) at another time and odometer.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function fill(string $at, string $odometer, array $overrides = []): array
    {
        return $overrides + ['filled_at' => $at, 'odometer' => $odometer] + self::FILL;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicle(
        App $app,
        FuelType $fuel = FuelType::Petrol,
        string $make = 'Volkswagen',
        string $model = 'Golf',
    ): Vehicle {
        return $this->service($app, VehicleService::class)
            ->create($this->owner($app), new VehicleData(VehicleType::Car, $make, $model, $fuel));
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
     * @return list<FuelEntry>
     */
    private function entries(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
    }
}
