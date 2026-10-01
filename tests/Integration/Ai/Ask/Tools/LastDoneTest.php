<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `last_done` (spec.md §7.26): the latest record of a category, and a
 * schedule's last and next.
 */
final class LastDoneTest extends AskTestCase
{
    use ToolsATesting;

    public function testTheLatestRecordOfACategory(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d');
        $this->record($app, $bmw, '2025-02-01', MaintenanceCategory::Oil, 'Oil and filter', '15000');
        $this->record($app, $bmw, '2026-03-14', MaintenanceCategory::Oil, 'Oil change', '24100');
        $this->record($app, $bmw, '2026-05-01', MaintenanceCategory::Brakes, 'Front pads', '25000');

        $run = $this->call($app, $owner, 'last_done', ['vehicle' => $bmw->id, 'category' => 'oil']);
        self::assertNotNull($run->result);
        $data = new JsonDoc($run->result->data);

        self::assertSame('2026-03-14', $data->get('last_record', 'date'));
        self::assertSame('Oil change', $data->get('last_record', 'title'));
        self::assertSame('24100.000', $data->get('last_record', 'odometer', 'km'));
        self::assertSame('/vehicles/' . $bmw->id . '/maintenance?category=oil', $run->result->link);
        self::assertContains('14 Mar 2026', $run->result->figures);
    }

    public function testAScheduleByTitleWithItsNextDue(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d');
        $schedule = $this->service($app, ScheduleService::class)->create($bmw, new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            intervalMonths: 12,
            baselineDoneOn: LocalTime::parseDate('2026-01-20'),
        ));

        $data = $this->data($app, $owner, 'last_done', ['vehicle' => $bmw->id, 'schedule' => 'annual']);

        self::assertSame($schedule->id, $data->get('schedules', 0, 'id'));
        self::assertSame('2026-01-20', $data->get('schedules', 0, 'last_done_on'));
        self::assertSame('2027-01-20', $data->get('schedules', 0, 'next_due_on'));

        $byId = $this->data($app, $owner, 'last_done', ['vehicle' => $bmw->id, 'schedule' => (string) $schedule->id]);
        self::assertSame('Annual service', $byId->get('schedules', 0, 'title'));
    }

    public function testACategoryOrScheduleIsNeeded(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d');

        $run = $this->call($app, $owner, 'last_done', ['vehicle' => $bmw->id]);

        self::assertSame('Give a category or a schedule.', $run->error);
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        [$app] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d');

        $run = $this->call($app, $this->createMember($app), 'last_done', ['vehicle' => $bmw->id, 'category' => 'oil']);

        self::assertSame(ToolKit::NOT_FOUND, $run->error);
    }

    public function testNotOfferedWithMaintenanceOffAndTheSchemaIsValid(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_MAINTENANCE' => 'false']);
        self::assertNotContains('last_done', $this->offered($app, $owner));

        [$app] = $this->askApp();
        $this->assertSchemaFits($app, 'last_done', ['vehicle' => 1, 'category' => 'oil', 'schedule' => 'Annual']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function record(
        App $app,
        Vehicle $vehicle,
        string $date,
        MaintenanceCategory $category,
        string $title,
        string $km,
    ): void {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);
        $this->service($app, MaintenanceService::class)->create(
            $vehicle,
            new MaintenanceEntryData($day, $category, $title, '50.00', $km),
            new DateTimeZone('Europe/London'),
        );
    }
}
