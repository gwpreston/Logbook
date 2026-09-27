<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The mileage log: manual readings, readings from fill-ups in the same
 * series, and plausibility warnings that never block. The owner uses miles.
 */
final class OdometerTest extends AppTestCase
{
    public function testAddEditAndDeleteAManualReading(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $car = $this->vehicle($app);
        $base = '/vehicles/' . $car->id . '/odometer';

        $empty = self::body($browser->get($base));
        self::assertStringContainsString('No readings yet', $empty);
        self::assertStringContainsString('aria-current="page">', $empty);

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('including 0 for a new vehicle', $form);
        self::assertStringContainsString('type="datetime-local"', $form);

        // Zero is a legitimate first reading.
        $created = $browser->post($base . '/new', ['reading' => '0', 'recorded_at' => '2026-01-10T10:00', 'note' => 'Delivery']);
        self::assertSame($base, $created->getHeaderLine('Location'));
        self::assertStringContainsString('The reading was saved.', self::body($browser->follow($created)));

        $browser->post($base . '/new', ['reading' => '1234.5', 'recorded_at' => '2026-07-01T09:15', 'note' => '']);
        $readings = $this->readings($app, $car);
        self::assertCount(2, $readings);
        self::assertSame('1986.735', $readings[1]->readingKm, '1,234.5 mi in km');
        self::assertSame('2026-07-01 08:15:00', $readings[1]->recordedAt->format('Y-m-d H:i:s'), 'BST stored as UTC');
        self::assertSame(OdometerSource::Manual, $readings[1]->source);

        $list = self::body($browser->get($base));
        self::assertStringContainsString('1,235 mi', $list);
        self::assertStringContainsString('+1,235 mi', $list, 'distance since the reading before');
        self::assertStringContainsString('Delivery', $list);
        self::assertStringContainsString('data-chart=', $list);
        self::assertStringContainsString('Monthly average', $list);

        $editPath = $base . '/' . $readings[1]->id . '/edit';
        self::assertStringContainsString('value="1234.5"', self::body($browser->get($editPath)), 'miles come back exactly');
        $updated = $browser->post($editPath, ['reading' => '1300', 'recorded_at' => '2026-07-01T09:15', 'note' => 'Corrected']);
        self::assertStringContainsString('The reading was updated.', self::body($browser->follow($updated)));
        self::assertSame('Corrected', $this->readings($app, $car)[1]->note);

        $confirm = self::body($browser->get($base . '/' . $readings[1]->id . '/delete'));
        self::assertStringContainsString('Delete this reading?', $confirm);
        $deleted = $browser->post($base . '/' . $readings[1]->id . '/delete');
        self::assertStringContainsString('The reading of 1,300 mi', self::body($browser->follow($deleted)));
        self::assertCount(1, $this->readings($app, $car));
    }

    public function testImplausibleReadingsAreSavedWithAWarning(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $car = $this->vehicle($app);
        $base = '/vehicles/' . $car->id . '/odometer';
        $browser->post($base . '/new', ['reading' => '10000', 'recorded_at' => '2026-05-01T10:00', 'note' => '']);

        $backwards = $browser->post($base . '/new', ['reading' => '9990', 'recorded_at' => '2026-05-08T10:00', 'note' => '']);
        self::assertSame(303, $backwards->getStatusCode(), 'warn, never block');
        $html = self::body($browser->follow($backwards));
        self::assertStringContainsString('The reading was saved.', $html);
        self::assertStringContainsString(
            'Saved, but this reading is lower than the one before it (10,000 mi on 1 May 2026)',
            $html,
        );
        self::assertStringContainsString('Lower than the reading before it', $html, 'flagged in the list too');

        // 10,350 typed as 103,500.
        $jump = $browser->post($base . '/new', ['reading' => '103500', 'recorded_at' => '2026-05-15T10:00', 'note' => '']);
        self::assertStringContainsString('check for a typo', self::body($browser->follow($jump)));
        self::assertCount(3, $this->readings($app, $car));
    }

    public function testFillUpReadingsJoinTheSameSeries(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $car = $this->vehicle($app);
        $browser->post('/vehicles/' . $car->id . '/odometer/new', self::reading('10000', '2026-06-01T10:00'));
        $browser->post('/vehicles/' . $car->id . '/fuel/new', [
            'filled_at' => '2026-06-10T10:00',
            'odometer' => '10250',
            'fuel' => 'petrol',
            'volume' => '40',
            'price' => '1.5',
            'total' => '',
        ]);

        $readings = $this->readings($app, $car);
        self::assertSame([OdometerSource::Manual, OdometerSource::Fuel], array_map(
            static fn (OdometerReading $r): OdometerSource => $r->source,
            $readings,
        ));
        $fromFuel = $readings[1];

        $list = self::body($browser->get('/vehicles/' . $car->id . '/odometer'));
        self::assertStringContainsString('10,250 mi', $list);
        self::assertStringContainsString('Fill-up', $list);
        self::assertStringContainsString('+250 mi', $list);

        // A fill-up's reading is changed through the fill-up.
        $edit = $browser->get('/vehicles/' . $car->id . '/odometer/' . $fromFuel->id . '/edit');
        self::assertSame(
            '/vehicles/' . $car->id . '/fuel/' . $fromFuel->fuelEntryId . '/edit',
            $edit->getHeaderLine('Location'),
        );
        self::assertSame(404, $browser->get('/vehicles/' . $car->id . '/odometer/' . $fromFuel->id . '/delete')->getStatusCode());
        $delete = '/vehicles/' . $car->id . '/odometer/' . $fromFuel->id . '/delete';
        self::assertSame(404, $browser->post($delete)->getStatusCode());
        self::assertCount(2, $this->readings($app, $car));

        // A fill-up behind the manual reading warns too.
        $warned = $browser->post('/vehicles/' . $car->id . '/fuel/new', [
            'filled_at' => '2026-06-12T10:00',
            'odometer' => '9000',
            'fuel' => 'petrol',
            'volume' => '10',
            'price' => '1.5',
            'total' => '',
        ]);
        self::assertStringContainsString('Saved, but this reading is lower', self::body($browser->follow($warned)));
    }

    public function testTheOverviewShowsTheCurrentOdometer(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $car = $this->vehicle($app);
        $browser->post('/vehicles/' . $car->id . '/odometer/new', self::reading('48412', '2026-06-01T10:00'));

        $html = self::body($browser->get('/vehicles/' . $car->id));
        self::assertStringContainsString('48,412 mi', $html);
        self::assertStringContainsString('as of 1 Jun 2026', $html);
        self::assertStringContainsString('href="/vehicles/' . $car->id . '/odometer"', $html);
        self::assertStringContainsString('No fill-ups yet', $html);
    }

    public function testValidation(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $car = $this->vehicle($app);
        $browser->get('/vehicles/' . $car->id . '/odometer/new');

        $response = $browser->post('/vehicles/' . $car->id . '/odometer/new', self::reading('-5', 'yesterday', 'x'));
        $html = self::body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Must be at least 0.', $html);
        self::assertStringContainsString('Enter a valid date and time.', $html);
        self::assertSame([], $this->readings($app, $car));
    }

    /**
     * @return array<string, string>
     */
    private static function reading(string $reading, string $at, string $note = ''): array
    {
        return ['reading' => $reading, 'recorded_at' => $at, 'note' => $note];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicle(App $app): Vehicle
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol));
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
