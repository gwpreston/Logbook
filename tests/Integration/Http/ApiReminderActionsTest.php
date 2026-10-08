<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Reminder actions and closed reminders (Phase 39.1, spec.md §7.20): done,
 * dismiss and reopen as the Reminders page's forms, safe to repeat, `Log`
 * on the reminder's vehicle, refused on an archived vehicle or with a read
 * key; `?closed=1` and `?status=done|dismissed` list the closed ones.
 */
final class ApiReminderActionsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    private function manual(string $title, string $due): int
    {
        $response = $this->api->post('/vehicles/' . $this->golf->id . '/reminders', ['title' => $title, 'due_on' => $due]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return ApiClient::json($response)->int('entry', 'id');
    }

    public function testDoneDismissAndReopenAreSafeToRepeat(): void
    {
        $wash = $this->manual('Wash the car', '2026-09-28');
        $wax = $this->manual('Wax the car', '2026-10-20');

        $done = ApiClient::json($this->api->post('/reminders/' . $wash . '/done', []));
        self::assertSame('done', $done->get('status'));
        self::assertFalse($done->get('unchanged'));
        self::assertSame('2026-09-30T12:00:00Z', $done->get('closed_at'));
        $again = ApiClient::json($this->api->post('/reminders/' . $wash . '/done', []));
        self::assertTrue($again->get('unchanged'));
        self::assertSame('2026-09-30T12:00:00Z', $again->get('closed_at'), 'nothing written');

        self::assertSame('dismissed', ApiClient::json($this->api->post('/reminders/' . $wax . '/dismiss', []))->get('status'));

        self::assertSame([$wax, $wash], $this->ids('/reminders?closed=1'));
        self::assertSame([$wash], $this->ids('/reminders?status=done'));
        self::assertSame([$wax], $this->ids('/reminders?status=dismissed&vehicle=' . $this->golf->id));
        self::assertSame([], $this->ids('/reminders'));

        $reopened = ApiClient::json($this->api->post('/reminders/' . $wash . '/reopen', []));
        self::assertSame('overdue', $reopened->get('status'), 'the status today calls for');
        self::assertNull($reopened->get('closed_at'));
        self::assertFalse($reopened->get('unchanged'));
        self::assertTrue(ApiClient::json($this->api->post('/reminders/' . $wash . '/reopen', []))->get('unchanged'));
        self::assertSame([$wash], $this->ids('/reminders'));
    }

    public function testTheActionsFollowThePagesAccess(): void
    {
        $wash = $this->manual('Wash the car', '2026-09-28');

        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $refused = $this->api($this->app, $this->apiKey($this->app, $viewer))->post('/reminders/' . $wash . '/done', []);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('forbidden', ApiClient::json($refused)->get('code'));

        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $logger->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $logged = $this->api($this->app, $this->apiKey($this->app, $logger))->post('/reminders/' . $wash . '/done', []);
        self::assertSame(200, $logged->getStatusCode(), 'Log is enough, as on the page');

        $stranger = $this->createMember($this->app, 'stranger');
        self::assertSame(404, $this->api($this->app, $this->apiKey($this->app, $stranger))
            ->post('/reminders/' . $wash . '/reopen', [])->getStatusCode());

        $readKey = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        $scope = $readKey->post('/reminders/' . $wash . '/reopen', []);
        self::assertSame(403, $scope->getStatusCode());
        self::assertSame('insufficient_scope', ApiClient::json($scope)->get('code'));

        self::assertSame(404, $this->api->post('/reminders/999999/done', [])->getStatusCode());
    }

    public function testAnArchivedVehiclesReminderIsNotChanged(): void
    {
        $wash = $this->manual('Wash the car', '2026-09-28');
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);

        $response = $this->api->post('/reminders/' . $wash . '/done', []);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('vehicle_archived', ApiClient::json($response)->get('code'));
    }

    public function testAReminderOfASwitchedOffModuleIsNotFound(): void
    {
        $this->service($this->app, ScheduleService::class)->create($this->golf, new MaintenanceScheduleData(
            MaintenanceCategory::Oil,
            'Oil change',
            intervalMonths: 12,
            baselineDoneOn: new DateTimeImmutable('2025-09-01', new DateTimeZone('UTC')),
        ));
        $id = ApiClient::json($this->api->get('/reminders'))->int('items', 0, 'id');
        $this->service($this->app, FeatureToggles::class)
            ->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Maintenance)));

        self::assertSame(404, $this->api->post('/reminders/' . $id . '/done', [])->getStatusCode());
    }

    /**
     * @return list<int>
     */
    private function ids(string $path): array
    {
        $doc = ApiClient::json($this->api->get($path));
        $items = $doc->get('items');
        self::assertIsArray($items);

        return array_map(static fn (int $i): int => $doc->int('items', $i, 'id'), array_keys($items));
    }
}
