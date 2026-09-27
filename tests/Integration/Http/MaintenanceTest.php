<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\MaintenanceScheduleRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Maintenance end to end: the service history (cost 0 is valid), the
 * odometer reading each entry writes, and recurring schedules whose next-due
 * point follows the entries that complete them. The owner uses UK units
 * (miles, GBP, Europe/London); "today" is 27 Sep 2026.
 */
final class MaintenanceTest extends AppTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    private const array ENTRY = [
        'performed_on' => '2026-09-01',
        'odometer' => '30000',
        'category' => 'service',
        'title' => 'Annual service',
        'cost' => '0',
        'vendor' => '',
        'description' => '',
        'schedule' => '',
    ];

    public function testCrudWithZeroCostKeepsTheOdometerInStep(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/maintenance';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('value="2026-09-27"', $form, 'defaults to today');
        self::assertStringContainsString('enctype="multipart/form-data"', $form);

        $created = $browser->post($base . '/new', self::ENTRY);
        self::assertSame(303, $created->getStatusCode(), 'a cost of 0 saves');
        self::assertSame($base, $created->getHeaderLine('Location'));
        $list = self::body($browser->follow($created));
        self::assertStringContainsString('“Annual service” was added to the service history.', $list);
        self::assertStringContainsString('£0.00', $list);

        $entry = $this->entries($app, $golf)[0];
        self::assertSame('0.000', $entry->data->cost);
        self::assertSame('48280.320', $entry->data->odometerKm, '30,000 mi in km');

        $readings = $this->service($app, OdometerReadingRepository::class);
        $reading = $readings->findByMaintenanceEntry($golf->id, $entry->id);
        self::assertNotNull($reading, 'an entry with an odometer writes a reading');
        self::assertSame(OdometerSource::Maintenance, $reading->source);
        self::assertSame('48280.320', $reading->readingKm);
        self::assertSame('2026-09-01 11:00:00', $reading->recordedAt->format('Y-m-d H:i:s'), 'noon BST on the day');

        // The mileage log sends a maintenance reading to its entry.
        $odometerEdit = $browser->get('/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/edit');
        self::assertSame($base . '/' . $entry->id . '/edit', $odometerEdit->getHeaderLine('Location'));

        // Edit: shown in the owner's units, saved in place, reading follows.
        $editPath = $base . '/' . $entry->id . '/edit';
        $edit = self::body($browser->get($editPath));
        self::assertStringContainsString('value="30000"', $edit);
        self::assertStringContainsString('value="2026-09-01"', $edit);
        $changes = ['odometer' => '30100', 'cost' => '189.5', 'vendor' => 'Main Street Motors'];
        $updated = $browser->post($editPath, $changes + self::ENTRY);
        self::assertSame($base, $updated->getHeaderLine('Location'));
        self::assertCount(1, $this->entries($app, $golf), 'updated in place');
        self::assertSame('189.500', $this->entries($app, $golf)[0]->data->cost);
        self::assertSame('48441.254', $readings->findByMaintenanceEntry($golf->id, $entry->id)?->readingKm);

        // Clearing the odometer removes the reading; the entry stays.
        $browser->post($editPath, ['odometer' => ''] + self::ENTRY);
        self::assertNull($readings->findByMaintenanceEntry($golf->id, $entry->id));
        self::assertNull($this->entries($app, $golf)[0]->data->odometerKm);

        // Delete (with confirmation).
        $browser->post($editPath, self::ENTRY);
        self::assertStringContainsString('Delete this entry?', self::body($browser->get($base . '/' . $entry->id . '/delete')));
        $deleted = $browser->post($base . '/' . $entry->id . '/delete');
        self::assertSame($base, $deleted->getHeaderLine('Location'));
        self::assertSame([], $this->entries($app, $golf));
        self::assertSame([], $readings->listForVehicle($golf->id), 'its reading went with it');
        self::assertSame(404, $browser->get($editPath)->getStatusCode());
    }

    public function testValidationErrorsReRenderTheFormWithInput(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $response = $browser->post('/vehicles/' . $golf->id . '/maintenance/new', [
            'title' => '',
            'cost' => '-1',
            'vendor' => 'Kwik Fit',
        ] + self::ENTRY);

        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('Must be at least 0.', $html);
        self::assertStringContainsString('value="Kwik Fit"', $html, 'input is kept');
        self::assertSame([], $this->entries($app, $golf));
    }

    public function testSchedulesComputeAndAdvanceTheirNextDuePoint(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/maintenance';

        // Every 10,000 mi or 12 months; last done before logging began.
        $created = $browser->post($base . '/schedules/new', [
            'title' => 'Annual service',
            'category' => 'service',
            'interval_distance' => '10000',
            'interval_months' => '12',
            'last_done_on' => '2025-09-15',
            'last_done_odometer' => '20000',
        ]);
        self::assertSame($base, $created->getHeaderLine('Location'));
        $schedule = $this->schedules($app, $golf)[0];
        self::assertSame('2026-09-15', $schedule->nextDue->on?->format('Y-m-d'), 'baseline + 12 months');
        self::assertSame('48280.320', $schedule->nextDue->km, '(20,000 + 10,000) mi in km');

        $page = self::body($browser->get($base));
        self::assertStringContainsString('Every 10,000 mi or 12 months', $page);
        self::assertStringContainsString('Next due 15 Sept 2026 or at 30,000 mi', $page);
        self::assertStringContainsString('Overdue', $page, '15 Sep has passed');
        self::assertStringContainsString($base . '/new?schedule=' . $schedule->id, $page, 'the "Log it" shortcut');

        // "Log it" pre-fills the entry, which then completes the schedule.
        $form = self::body($browser->get($base . '/new?schedule=' . $schedule->id));
        self::assertStringContainsString('value="Annual service"', $form);
        self::assertMatchesRegularExpression('/<option value="' . $schedule->id . '" selected>/', $form);
        $browser->post($base . '/new', ['schedule' => (string) $schedule->id, 'odometer' => '29500'] + self::ENTRY);
        $advanced = $this->schedules($app, $golf)[0];
        self::assertSame('2026-09-01', $advanced->lastDone->on?->format('Y-m-d'));
        self::assertSame('2027-09-01', $advanced->nextDue->on?->format('Y-m-d'));
        self::assertSame('63569.088', $advanced->nextDue->km, '(29,500 + 10,000) mi in km');
        self::assertStringNotContainsString('Overdue', self::body($browser->get($base)));

        // Moving the entry moves the schedule.
        $entry = $this->entries($app, $golf)[0];
        $browser->post($base . '/' . $entry->id . '/edit', [
            'schedule' => (string) $schedule->id,
            'performed_on' => '2026-08-31',
            'odometer' => '29400',
        ] + self::ENTRY);
        self::assertSame('2027-08-31', $this->nextDueOn($app, $golf));

        // Unlinking (or deleting) it falls back to the baseline.
        $browser->post($base . '/' . $entry->id . '/edit', ['schedule' => ''] + self::ENTRY);
        self::assertSame('2026-09-15', $this->nextDueOn($app, $golf));

        // Deleting the schedule keeps the history.
        $browser->post($base . '/' . $entry->id . '/edit', ['schedule' => (string) $schedule->id] + self::ENTRY);
        $browser->post($base . '/schedules/' . $schedule->id . '/delete');
        self::assertSame([], $this->schedules($app, $golf));
        self::assertCount(1, $this->entries($app, $golf));
        self::assertNull($this->entries($app, $golf)[0]->data->scheduleId);
    }

    public function testASchedulesDistanceIsProjectedFromMileage(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        // 1,000 mi over 50 days = 20 mi a day, now at 21,000 mi.
        $odometer = '/vehicles/' . $golf->id . '/odometer/new';
        $browser->post($odometer, ['reading' => '20000', 'recorded_at' => '2026-08-08T09:00', 'note' => '']);
        $browser->post($odometer, ['reading' => '21000', 'recorded_at' => '2026-09-27T09:00', 'note' => '']);
        $browser->post('/vehicles/' . $golf->id . '/maintenance/schedules/new', [
            'title' => 'Tyre rotation',
            'category' => 'tyres',
            'interval_distance' => '2000',
            'interval_months' => '',
            'last_done_on' => '',
            'last_done_odometer' => '20000',
        ]);

        // Due at 22,000 mi: 1,000 mi to go at 20 mi a day ≈ 50 days.
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance'));
        self::assertStringContainsString('Next due at 22,000 mi', $page);
        self::assertStringContainsString('Due in about 50 days', $page);

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('Tyre rotation', $overview, 'the overview shows what is due');
    }

    public function testCategoryFilterAndSubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/logbook/vehicles/' . $golf->id . '/maintenance';

        $browser->post($base . '/new', self::ENTRY);
        $tyres = ['category' => 'tyres', 'title' => 'Two front tyres', 'odometer' => ''];
        $created = $browser->post($base . '/new', $tyres + self::ENTRY);
        self::assertSame($base, $created->getHeaderLine('Location'));

        // Hard refresh with the prefix stripped by the proxy.
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance?category=tyres'));
        self::assertStringContainsString('Two front tyres', $page);
        self::assertStringNotContainsString('<span class="list__title">Annual service</span>', $page);
        self::assertStringContainsString('href="' . $base . '?category=tyres" aria-current="true"', $page);
        self::assertStringContainsString('href="/logbook/vehicles/' . $golf->id . '/documents"', $page, 'the documents tab');
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
     * @return list<MaintenanceEntry>
     */
    private function entries(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<MaintenanceSchedule>
     */
    private function schedules(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, MaintenanceScheduleRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function nextDueOn(App $app, Vehicle $vehicle): ?string
    {
        $schedules = $this->schedules($app, $vehicle);
        self::assertCount(1, $schedules);

        return $schedules[0]->nextDue->on?->format('Y-m-d');
    }
}
