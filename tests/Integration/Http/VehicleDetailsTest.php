<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DI\Container;
use Doctrine\DBAL\DriverManager;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Tests\Support\AppTestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Vehicle details (Phase 9.1, spec.md §7.1, §7.2): variant, first
 * registration and the current odometer on the add form, which writes the
 * vehicle's first manual reading in the same transaction.
 */
final class VehicleDetailsTest extends AppTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    private const array FOCUS = [
        'type' => 'car',
        'make' => 'Ford',
        'model' => 'Focus',
        'variant' => '1.5 EcoBoost ST-Line X',
        'year' => '2019',
        'first_registered_on' => '2019-03-14',
        'registration' => 'fd19 stl',
        'fuel_type' => 'petrol',
        'currency' => '',
    ];

    public function testAddingWithACurrentOdometerInMilesWritesOneManualReadingInKm(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('name="current_odometer"', $form);
        self::assertStringContainsString('name="variant"', $form);
        self::assertStringContainsString('name="first_registered_on"', $form);
        self::assertStringContainsString('max="2026-09-27"', $form, 'no registration after today');

        $created = $browser->post('/vehicles/new', ['current_odometer' => '42,180'] + self::FOCUS);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        $focus = $this->onlyVehicle($app);

        $readings = $this->readings($app, $focus);
        self::assertCount(1, $readings);
        self::assertSame(OdometerSource::Manual, $readings[0]->source);
        self::assertSame('67882.130', $readings[0]->readingKm, '42,180 mi in km');
        self::assertSame(self::NOW, $readings[0]->recordedAt->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('1.5 EcoBoost ST-Line X', $focus->data->variant);
        self::assertSame('2019-03-14', $focus->data->firstRegisteredOn?->format('Y-m-d'));

        $overview = self::body($browser->follow($created));
        self::assertStringContainsString('2019 Ford Focus 1.5 EcoBoost ST-Line X', $overview);
        self::assertStringContainsString('42,180 mi', $overview);
        self::assertStringContainsString('14 Mar 2019', $overview);
        self::assertStringContainsString('(7 yrs 6 mo old)', $overview);

        self::assertStringContainsString('42,180 mi', self::body($browser->get('/garage')));
        self::assertStringContainsString('42,180 mi', self::body($browser->get('/')));

        $mileage = self::body($browser->get('/vehicles/' . $focus->id . '/odometer'));
        self::assertStringContainsString('Manual', $mileage);
        self::assertStringContainsString('Per year since first registered', $mileage);
        // 42,180 mi over 2,754 days (7.54 years).
        self::assertStringContainsString('5,594 mi', $mileage);
    }

    public function testAZeroOdometerIsAReadingAndABlankOneIsNot(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['current_odometer' => '0', 'variant' => 'New'] + self::FOCUS);
        $browser->post('/vehicles/new', ['current_odometer' => '', 'variant' => 'Blank'] + self::FOCUS);

        $new = $this->vehicleWithVariant($app, 'New');
        $readings = $this->readings($app, $new);
        self::assertCount(1, $readings);
        self::assertSame('0.000', $readings[0]->readingKm);
        self::assertStringContainsString('0 mi', self::body($browser->get('/vehicles/' . $new->id)));

        self::assertSame([], $this->readings($app, $this->vehicleWithVariant($app, 'Blank')));
    }

    public function testAFailedReadingLeavesNoVehicleBehind(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        // Readings go to a database without the table, so writing one fails
        // after the vehicle's insert, inside its transaction.
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(OdometerService::class, new OdometerService(
            new OdometerReadingRepository($broken),
            $this->service($app, AttachmentService::class),
            $this->service($app, ClockInterface::class),
        ));
        $browser = $this->signedIn($app);

        $browser->get('/vehicles/new');
        // Control: without a starting reading the vehicle is saved as usual.
        $saved = $browser->post('/vehicles/new', ['current_odometer' => ''] + self::FOCUS);
        self::assertSame(303, $saved->getStatusCode());
        self::assertCount(1, $this->ownedVehicles($app, $this->owner($app)->id));

        $response = $browser->post('/vehicles/new', ['current_odometer' => '100', 'variant' => 'Doomed'] + self::FOCUS);

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $this->ownedVehicles($app, $this->owner($app)->id), 'the inserted vehicle was rolled back');
    }

    public function testEditingShowsTheCurrentReadingAndNeverWritesOne(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['current_odometer' => '100'] + self::FOCUS);
        $focus = $this->onlyVehicle($app);
        $path = '/vehicles/' . $focus->id;

        $edit = self::body($browser->get($path . '/edit'));
        self::assertStringNotContainsString('name="current_odometer"', $edit);
        self::assertStringContainsString('100 mi', $edit);
        self::assertStringContainsString('href="' . $path . '/odometer/new" data-modal>Add reading</a>', $edit);
        self::assertStringContainsString('value="1.5 EcoBoost ST-Line X"', $edit);
        self::assertStringContainsString('value="2019-03-14"', $edit);

        $saved = $browser->post($path . '/edit', ['current_odometer' => '99999', 'variant' => 'ST-Line'] + self::FOCUS);
        self::assertSame(303, $saved->getStatusCode());
        $readings = $this->readings($app, $focus);
        self::assertCount(1, $readings, 'an edit never adds a reading');
        self::assertSame('160.934', $readings[0]->readingKm);
        self::assertSame('ST-Line', $this->onlyVehicle($app)->data->variant);

        // Without readings the edit form says so.
        $browser->post($path . '/odometer/' . $readings[0]->id . '/delete');
        self::assertStringContainsString('No readings yet', self::body($browser->get($path . '/edit')));
    }

    public function testAModelYearAfterTheRegistrationYearIsSavedWithAWarning(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $created = $browser->post('/vehicles/new', ['year' => '2024', 'first_registered_on' => '2021-05-01'] + self::FOCUS);
        self::assertSame(303, $created->getStatusCode());
        self::assertStringContainsString(
            'Saved, but the year is 2024 and it was first registered in 2021: check both.',
            self::body($browser->follow($created)),
        );

        $future = ['first_registered_on' => '2026-09-28', 'current_odometer' => '5'];
        $invalid = $browser->post('/vehicles/new', $future + self::FOCUS);
        self::assertSame(422, $invalid->getStatusCode());
        $html = self::body($invalid);
        self::assertStringContainsString('The first registration date cannot be in the future.', $html);
        self::assertStringContainsString('value="5"', $html, 'typed values are kept');
        self::assertStringContainsString('value="1.5 EcoBoost ST-Line X"', $html);
    }

    public function testTheDescriptiveLineShowsWhereverTheVehicleIsDescribed(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $long = 'xDrive30d M Sport Pro Edition with the Technology and Comfort Packs';
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['nickname' => 'The Focus'] + self::FOCUS);
        $browser->post('/vehicles/new', ['make' => 'BMW', 'model' => 'X5', 'variant' => $long, 'year' => ''] + self::FOCUS);
        $focus = $this->vehicleWithVariant($app, '1.5 EcoBoost ST-Line X');
        $line = '2019 Ford Focus 1.5 EcoBoost ST-Line X';

        $garage = self::body($browser->get('/garage'));
        self::assertStringContainsString(
            '<p class="vehicle-card__sub truncate" title="' . $line . '">' . $line . '</p>',
            $garage,
        );
        self::assertStringContainsString('title="BMW X5 ' . $long . '"', $garage, 'no year: skipped');

        $dashboard = self::body($browser->get('/'));
        self::assertStringContainsString('<span class="vehicle-tile__sub truncate" title="' . $line . '">', $dashboard);
        $pinned = self::body($browser->get('/?vehicle=' . $focus->id));
        self::assertStringContainsString('<span class="truncate" title="' . $line . '">' . $line . '</span>', $pinned);

        $path = '/vehicles/' . $focus->id;
        self::assertStringContainsString('<p class="muted">' . $line . '</p>', self::body($browser->get($path)));
        self::assertStringContainsString($line, self::body($browser->get($path . '/delete')));
        $picker = self::body($browser->get('/log/new/odometer'));
        self::assertStringContainsString('<span class="pick__sub truncate" title="' . $line . '">', $picker);
    }

    public function testNoAgeOrLifetimeAverageWithoutARegistrationDateOrUnderNinetyDays(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        $unregistered = ['first_registered_on' => '', 'variant' => 'Unregistered', 'current_odometer' => '100'];
        $browser->post('/vehicles/new', $unregistered + self::FOCUS);
        $new = ['first_registered_on' => '2026-08-01', 'variant' => 'New', 'current_odometer' => '100'];
        $browser->post('/vehicles/new', $new + self::FOCUS);

        $unregistered = $this->vehicleWithVariant($app, 'Unregistered');
        self::assertStringNotContainsString('First registered', self::body($browser->get('/vehicles/' . $unregistered->id)));
        $mileage = self::body($browser->get('/vehicles/' . $unregistered->id . '/odometer'));
        self::assertStringNotContainsString('Per year since', $mileage);

        $new = $this->vehicleWithVariant($app, 'New');
        self::assertStringContainsString('(1 mo old)', self::body($browser->get('/vehicles/' . $new->id)));
        self::assertStringNotContainsString('Per year since', self::body($browser->get('/vehicles/' . $new->id . '/odometer')));
    }

    public function testALaterFillUpDatedBeforeTheStartingReadingSitsInOrder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['current_odometer' => '42000'] + self::FOCUS);
        $focus = $this->onlyVehicle($app);

        $fill = $browser->post('/vehicles/' . $focus->id . '/fuel/new', [
            'filled_at' => '2026-09-01T10:00',
            'odometer' => '41500',
            'fuel' => 'petrol',
            'volume' => '40',
            'price' => '1.5',
            'total' => '',
        ]);
        self::assertSame(303, $fill->getStatusCode(), self::body($fill));

        $readings = $this->readings($app, $focus);
        self::assertSame([OdometerSource::Fuel, OdometerSource::Manual], array_map(
            static fn (OdometerReading $r): OdometerSource => $r->source,
            $readings,
        ));
        $garage = self::body($browser->get('/garage'));
        self::assertStringContainsString('42,000 mi', $garage, 'the starting reading is still the latest');
    }

    public function testADatedStartingReadingIsLocalNoonAndSitsInOrder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('name="current_odometer_on"', $form);
        self::assertStringContainsString('value="2026-09-27"', $form, 'As of defaults to today');
        self::assertStringContainsString('When the figure was read, for example on the MOT certificate or at the sale.', $form);

        $dated = ['current_odometer' => '40000', 'current_odometer_on' => '2026-03-14'];
        $created = $browser->post('/vehicles/new', $dated + self::FOCUS);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        $focus = $this->onlyVehicle($app);
        $readings = $this->readings($app, $focus);
        self::assertCount(1, $readings, 'exactly one manual reading');
        self::assertSame(OdometerSource::Manual, $readings[0]->source);
        self::assertSame('2026-03-14T12:00:00Z', $readings[0]->recordedAt->format('Y-m-d\TH:i:s\Z'), 'local noon (GMT in March)');
        self::assertStringNotContainsString('before the first registration', self::body($browser->follow($created)));

        // Fill-ups before and after it sit in order.
        foreach ([['2026-02-01T09:00', '39000'], ['2026-06-01T09:00', '41000']] as [$at, $odometer]) {
            $fill = $browser->post('/vehicles/' . $focus->id . '/fuel/new', [
                'filled_at' => $at,
                'odometer' => $odometer,
                'fuel' => 'petrol',
                'volume' => '40',
                'price' => '1.5',
                'total' => '',
            ]);
            self::assertSame(303, $fill->getStatusCode(), self::body($fill));
        }
        self::assertSame([OdometerSource::Fuel, OdometerSource::Manual, OdometerSource::Fuel], array_map(
            static fn (OdometerReading $r): OdometerSource => $r->source,
            $this->readings($app, $focus),
        ));

        $mileage = self::body($browser->get('/vehicles/' . $focus->id . '/odometer'));
        self::assertStringContainsString('14 Mar 2026', $mileage);
        self::assertStringContainsString('40,000 mi', $mileage);
        self::assertStringContainsString('41,000 mi', $mileage, 'the latest');
        self::assertStringContainsString('41,000 mi', self::body($browser->get('/garage')));
        self::assertStringContainsString('41,000 mi', self::body($browser->get('/')));
    }

    public function testASameDayStartingReadingIsTheMomentOfSaving(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $browser->post('/vehicles/new', ['current_odometer' => '100', 'current_odometer_on' => '2026-09-27'] + self::FOCUS);
        $readings = $this->readings($app, $this->onlyVehicle($app));
        self::assertSame(self::NOW, $readings[0]->recordedAt->format('Y-m-d\TH:i:s\Z'));
    }

    public function testTheAsOfDateIsCheckedAndKeptOnAnError(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $future = $browser->post('/vehicles/new', self::dated('2026-09-28'));
        self::assertSame(422, $future->getStatusCode());
        self::assertStringContainsString('The odometer reading cannot be dated in the future.', self::body($future));
        self::assertStringContainsString('value="2026-09-28"', self::body($future), 'kept');

        $early = $browser->post('/vehicles/new', self::dated('1884-12-31'));
        self::assertStringContainsString('The odometer reading cannot be dated before 1885.', self::body($early));

        $other = $browser->post('/vehicles/new', ['make' => '', 'current_odometer_on' => '2026-01-05'] + self::FOCUS);
        self::assertSame(422, $other->getStatusCode());
        self::assertStringContainsString('value="2026-01-05"', self::body($other), 'kept on any validation error');
        self::assertSame([], $this->ownedVehicles($app, $this->owner($app)->id));
    }

    public function testAStartingReadingBeforeRegistrationIsSavedWithAWarning(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $created = $browser->post('/vehicles/new', [
            'current_odometer' => '8',
            'current_odometer_on' => '2019-03-01',
        ] + self::FOCUS);
        self::assertSame(303, $created->getStatusCode());
        self::assertStringContainsString(
            'Saved, but the odometer reading is dated before the first registration: check both.',
            self::body($browser->follow($created)),
        );
        self::assertCount(1, $this->readings($app, $this->onlyVehicle($app)));
    }

    public function testTheLifetimeAverageIsMeasuredToTheLatestReading(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        // Registered 14 Mar 2019; 22,695.6 mi read on 14 Mar 2024, five years later.
        $browser->post('/vehicles/new', ['current_odometer' => '22695.6', 'current_odometer_on' => '2024-03-14'] + self::FOCUS);
        $focus = $this->onlyVehicle($app);

        $mileage = self::body($browser->get('/vehicles/' . $focus->id . '/odometer'));
        // About 4,537 mi a year to the reading's date, not 3,010 mi a year to today.
        self::assertStringContainsString('4,537 mi', $mileage);
        self::assertStringNotContainsString('3,010 mi', $mileage);
        $overview = self::body($browser->get('/vehicles/' . $focus->id));
        self::assertStringContainsString('(7 yrs 6 mo old)', $overview, 'age is to today');
    }

    public function testTheOverviewNoLongerListsFillUpsAndLosesNoFigure(): void
    {
        foreach (['true', 'false'] as $fuel) {
            $app = $this->createApp(['FEATURES_FUEL' => $fuel]);
            $this->pinClock($app, self::NOW);
            $browser = $this->signedIn($app);
            $browser->get('/vehicles/new');
            $browser->post('/vehicles/new', ['current_odometer' => '100'] + self::FOCUS);
            $focus = $this->onlyVehicle($app);
            if ($fuel === 'true') {
                foreach ([['2026-09-01T09:00', '41000'], ['2026-09-20T09:00', '41400']] as [$at, $odometer]) {
                    $browser->post('/vehicles/' . $focus->id . '/fuel/new', [
                        'filled_at' => $at,
                        'odometer' => $odometer,
                        'fuel' => 'petrol',
                        'volume' => '40',
                        'price' => '1.5',
                        'total' => '',
                    ]);
                }
            }

            $overview = self::body($browser->get('/vehicles/' . $focus->id));
            self::assertStringNotContainsString('fills-heading', $overview, 'fuel ' . $fuel);
            self::assertStringNotContainsString('Recent fill-ups', $overview);
            self::assertStringContainsString('Recent history', $overview, 'which lists fill-ups with everything else');
            if ($fuel === 'true') {
                // Economy, cost per distance and spend stay in the stats row.
                self::assertStringContainsString('Average economy', $overview);
                self::assertStringContainsString('£120.00', $overview, 'spend');
                self::assertStringContainsString('Fill-up', $overview, 'in Recent history');
            } else {
                self::assertStringNotContainsString('Average economy', $overview);
            }
        }
    }

    public function testGermanLabels(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['current_odometer' => '100', 'first_registered_on' => '2025-09-27'] + self::FOCUS);
        $focus = $this->onlyVehicle($app);
        $this->connection($app)->update('users', ['locale' => 'de_DE'], ['id' => $this->owner($app)->id]);

        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('Variante / Ausstattung', $form);
        self::assertStringContainsString('Erstzulassung', $form);
        self::assertStringContainsString('Aktueller Kilometerstand', $form);
        self::assertStringContainsString('Stand vom', $form);
        self::assertStringContainsString('Kaufunterlagen', $form);
        self::assertStringContainsString('Verkaufsunterlagen', $form);
        self::assertStringContainsString('(1 Jahr alt)', self::body($browser->get('/vehicles/' . $focus->id)));
        $mileage = self::body($browser->get('/vehicles/' . $focus->id . '/odometer'));
        self::assertStringContainsString('Ø pro Jahr seit Erstzulassung', $mileage);
    }

    /**
     * The Focus with a starting reading of 100 read on $on.
     *
     * @return array<string, string>
     */
    private static function dated(string $on): array
    {
        return ['current_odometer' => '100', 'current_odometer_on' => $on] + self::FOCUS;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<OdometerReading>
     */
    private function readings(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, OdometerService::class)->history($vehicle)->readings;
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
    private function vehicleWithVariant(App $app, string $variant): Vehicle
    {
        foreach ($this->ownedVehicles($app, $this->owner($app)->id) as $vehicle) {
            if ($vehicle->data->variant === $variant) {
                return $vehicle;
            }
        }

        self::fail('No vehicle with variant ' . $variant);
    }
}
