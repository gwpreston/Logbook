<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * An entered, API or URL date must fall in 1900-2100 (spec.md §8, K42): the
 * ends of the old 0001-9999 range crashed or looped later code.
 */
final class DateRangeTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures {
        vehicle as private costVehicle;
    }

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

    public function testAFillUpOutsideTheRangeIsRefusedOnTheForm(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $golf = $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol));
        $browser->get('/vehicles/' . $golf->id . '/fuel/new');

        foreach (['9999-12-31T09:15', '0001-01-01T09:15', '2101-01-01T00:00', '1899-12-31T23:59'] as $at) {
            $response = $browser->post('/vehicles/' . $golf->id . '/fuel/new', ['filled_at' => $at] + self::FILL);
            self::assertSame(422, $response->getStatusCode(), $at);
            self::assertStringContainsString('Enter a date between 1900 and 2100.', self::body($response), $at);
        }

        foreach (['/', '/history', '/vehicles/' . $golf->id, '/reminders'] as $page) {
            self::assertSame(200, $browser->get($page)->getStatusCode(), $page);
        }
    }

    public function testUrlDatesOutsideTheRangeAreNeverAServerError(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $browser = $this->signedIn($app);

        $urls = [
            '/reports?range=custom&from=0001-01-01&to=0001-01-02',
            '/reports?range=custom&from=2020-01-01&to=9999-12-31',
            '/trips/claim?year=0000',
            '/trips/claim?year=9999',
            '/trips/claim?period=custom&from=0001-01-01&to=0001-12-31',
            '/history?year=0000',
            '/history?year=9999',
        ];
        foreach ($urls as $url) {
            self::assertSame(200, $browser->get($url)->getStatusCode(), $url);
        }
    }

    public function testTheApiRefusesDatesOutsideTheRange(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->costVehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));

        $response = $api->post('/vehicles/' . $golf->id . '/fuel', ['filled_at' => '9999-12-31T09:15'] + self::FILL);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(200, $api->get('/vehicles/' . $golf->id . '/fuel')->getStatusCode());

        foreach (['year=0000', 'year=9999', 'period=custom&from=0001-01-01&to=0001-02-01'] as $query) {
            $status = $api->get('/trips/claim?' . $query)->getStatusCode();
            self::assertContains($status, [400, 422], $query);
        }
        self::assertSame(200, $api->get('/trips/claim?year=2026')->getStatusCode());
    }

    /**
     * K43: a vehicle driven almost nothing projects a distance due date past
     * year 9999; the pages stay up and show the distance, not a date.
     */
    public function testADriverOfAlmostNothingStillGetsPages(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-29T10:00:00Z');
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $golf = $this->costVehicle($app);
        $this->reading($app, $golf, '100000', '2025-09-01T08:00:00Z');
        $this->reading($app, $golf, '100001', '2026-09-01T08:00:00Z');
        $baseline = LocalTime::parseDate('2020-01-01');
        self::assertNotNull($baseline);
        $this->service($app, ScheduleService::class)->create($golf, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Service',
            intervalKm: '20000.000',
            baselineDoneOn: $baseline,
            baselineDoneKm: '90000.000',
        ));

        $this->service($app, ReminderSync::class)->sync($owner);

        foreach (['/', '/reminders', '/vehicles/' . $golf->id, '/vehicles/' . $golf->id . '/maintenance'] as $page) {
            self::assertSame(200, $browser->get($page)->getStatusCode(), $page);
        }
        $rows = $this->service($app, \Doctrine\DBAL\Connection::class)
            ->fetchAllAssociative('SELECT due_on, due_km FROM reminders');
        self::assertCount(1, $rows, 'the service is a reminder');
        self::assertNull($rows[0]['due_on'], 'no date a century out is stored');
        self::assertEquals(110000, $rows[0]['due_km']);
    }
}
