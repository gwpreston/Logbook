<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The incident endpoints (spec.md §7.20, §7.29): logging through the form's
 * parser with a safe retry, the list and claims history as the key's user
 * may see them, and 404 with the module off. ApiClient checks every
 * response against openapi.json.
 */
final class ApiIncidentsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->path = '/vehicles/' . $this->golf->id . '/incidents';
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function incident(array $body = []): array
    {
        return $body + [
            'occurred_on' => '2026-03-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'damage_areas' => ['rear'],
            'odometer' => '42000',
            'distance_unit' => 'km',
            'other_party_name' => 'A. Driver',
            'claim_status' => 'settled',
            'insurer' => 'Aviva',
            'claim_number' => '4417',
            'excess' => '0',
            'payout' => '1000',
            'repair_estimate' => '1250.5',
        ];
    }

    public function testAPostLogsTheIncidentAndARetryWritesNothing(): void
    {
        $response = $this->api->post($this->path, self::incident());
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame('parked_damage', $entry->get('type'));
        self::assertSame(['rear'], $entry->get('damage_areas'));
        self::assertSame('42000.000', $entry->get('odometer'));
        self::assertSame('4417', $entry->get('claim', 'claim_number'));
        self::assertSame('0.000', $entry->get('claim', 'excess'), '0 is valid');
        self::assertSame('1250.500', $entry->get('claim', 'repair_estimate'));
        self::assertSame('A. Driver', $entry->get('other_party', 'name'));

        $retry = $this->api->post($this->path, self::incident());
        self::assertSame(200, $retry->getStatusCode());
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertCount(1, $this->service($this->app, IncidentRepository::class)->listForVehicle($this->golf->id));
    }

    public function testABreakdownRoundTrips(): void
    {
        // Phase 33.3 (#185): a breakdown with no damage, like any other type.
        $response = $this->api->post($this->path, self::incident(['type' => 'breakdown', 'damage_areas' => []]));
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame('breakdown', ApiClient::json($response)->doc('entry')->get('type'));

        $stored = $this->service($this->app, IncidentRepository::class)->listForVehicle($this->golf->id);
        self::assertCount(1, $stored);
        self::assertSame(IncidentType::Breakdown, $stored[0]->data->type);
        self::assertSame('breakdown', ApiClient::json($this->api->get($this->path))->get('items', 0, 'type'));
    }

    public function testAnInvalidIncidentIsRefusedWithTheApiFieldNames(): void
    {
        $response = $this->api->post($this->path, self::incident([
            'occurred_on' => '2026-10-02',
            'damage_areas' => ['bonnet'],
            'payout' => '-1',
        ]));
        self::assertSame(422, $response->getStatusCode());
        $fields = ApiClient::json($response)->keys('errors');
        foreach (['occurred_on', 'damage_areas', 'payout'] as $field) {
            self::assertContains($field, $fields);
        }
    }

    public function testTheListAndHistoryFollowTheAccessRules(): void
    {
        $this->api->post($this->path, self::incident());
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $this->api->post('/vehicles/' . $fiesta->id . '/incidents', self::incident([
            'occurred_on' => '2023-05-02',
            'type' => 'collision',
            'claim_number' => 'AB-77',
        ]));
        $this->service($this->app, VehicleService::class)->archive($this->owner, $fiesta);

        $list = ApiClient::json($this->api->get($this->path));
        self::assertCount(1, $list->doc('items'));
        self::assertTrue($list->get('items', 0, 'details'));

        $history = ApiClient::json($this->api->get('/incidents/history'));
        self::assertSame(['4417', 'AB-77'], $history->column('claim_number', 'items'), 'the sold car is included');
        self::assertStringNotContainsString('A. Driver', json_encode($history->toArray(), JSON_THROW_ON_ERROR));
        self::assertCount(1, ApiClient::json($this->api->get('/incidents/history?years=3'))->doc('items'));

        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $viewer));
        $seen = ApiClient::json($theirs->get($this->path))->doc('items', 0);
        self::assertFalse($seen->get('details'));
        self::assertNull($seen->get('claim'));
        self::assertNull($seen->get('other_party'));
        self::assertNull($seen->get('costs'), 'amounts follow cost access');
        self::assertSame(['rear'], $seen->get('damage_areas'));
        self::assertSame(403, $theirs->post($this->path, self::incident(['occurred_on' => '2026-04-01']))->getStatusCode());
    }

    public function testWithTheModuleOffEveryIncidentPathIsNotFound(): void
    {
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));

        self::assertSame(404, $this->api->get($this->path)->getStatusCode());
        self::assertSame(404, $this->api->post($this->path, self::incident())->getStatusCode());
        self::assertSame(404, $this->api->get('/incidents/history')->getStatusCode());
    }
}
