<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Api\ApiIncidents;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentForm;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Editing and deleting entries over the API (Phase 39.2, spec.md §7.20
 * *Phase 39*): `PATCH` lays the sent fields over the stored entry and goes
 * through the edit form; `DELETE` through the delete page. Both leave the
 * database exactly as the pages do, refuse a stale `If-Match`, an
 * archived vehicle and a derived reading, and follow `canChange`.
 * ApiClient checks every response against the OpenAPI description.
 */
final class ApiEntryEditTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** The tables an entry edit can touch, for the parity checks. */
    private const array TABLES = [
        'fuel_entries', 'odometer_readings', 'maintenance_entries', 'maintenance_schedules', 'compliance_documents',
        'reminders', 'expense_entries', 'trips', 'incidents', 'attachments',
    ];

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        // Metric, so the pages' own round trip through miles (which can move the
        // third decimal of a km) doesn't blur the parity checks.
        $this->owner = $this->createOwner($this->app, preferences: new DisplayPreferences(
            'en_GB',
            'Europe/London',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::LitresPer100Km,
            'GBP',
        ));
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * One entry of each list, the same on every vehicle it is given.
     *
     * @return array<string, int> list → entry id
     */
    private function garage(Vehicle $vehicle): array
    {
        $fill = $this->fillUp($this->app, $vehicle, '2026-08-01T07:30:00Z', '40000', '42.123', '61.37');
        $this->fillUp($this->app, $vehicle, '2026-09-01T08:00:00Z', '40700', '44.5', '66.01');
        $this->reading($this->app, $vehicle, '41000', '2026-09-20T12:00:00Z');
        $service = $this->maintenance($this->app, $vehicle, '2026-09-05', 'Annual service', '187.43', '40800');
        $document = $this->document(
            $this->app,
            $vehicle,
            ComplianceType::Inspection,
            '2025-10-15',
            '2026-10-14',
            '54.85',
            'Test centre',
        );
        $expense = $this->expense($this->app, $vehicle, '2026-09-05', '12.91');
        $trip = $this->service($this->app, TripService::class)->create($vehicle, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            '90.5',
            purpose: 'Site visit',
        ));
        $incident = $this->service($this->app, ApiIncidents::class)->logIncident($this->owner, $vehicle, [
            'occurred_on' => '2026-03-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'damage_areas' => ['rear'],
            'other_party_name' => 'A. Driver',
            'claim_number' => '4417',
        ])['incident'];

        $manual = null;
        foreach ($this->service($this->app, OdometerService::class)->history($vehicle)->readings as $candidate) {
            if ($candidate->isManual()) {
                $manual = $candidate->id;
            }
        }
        self::assertNotNull($manual);

        return [
            'fuel' => $fill->id,
            'odometer' => $manual,
            'maintenance' => $service->id,
            'documents' => $document->id,
            'expenses' => $expense->id,
            'trips' => $trip->id,
            'incidents' => $incident->id,
        ];
    }

    public function testAPatchChangesOnlyTheFieldsSentAndAnswersTheEntryWithItsNewTag(): void
    {
        $ids = $this->garage($this->golf);
        $path = '/vehicles/' . $this->golf->id . '/fuel/' . $ids['fuel'];
        $before = ApiClient::json($this->api->get($path))->toArray();
        $tag = $this->api->get($path)->getHeaderLine('ETag');

        $response = $this->api->patch($path, ['notes' => 'Shell, pump 4']);
        self::assertSame(200, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry')->toArray();
        self::assertSame('Shell, pump 4', $entry['notes']);
        self::assertSame($entry, ApiClient::json($this->api->get($path))->toArray(), 'answers the entry as it now reads');
        self::assertNotSame($tag, $response->getHeaderLine('ETag'));
        self::assertSame($this->api->get($path)->getHeaderLine('ETag'), $response->getHeaderLine('ETag'));
        foreach ($before as $field => $value) {
            if ($field !== 'notes') {
                self::assertSame($value, $entry[$field], $field . ' is unchanged');
            }
        }

        $cleared = ApiClient::json($this->api->patch($path, ['notes' => null]))->doc('entry');
        self::assertNull($cleared->get('notes'), 'null clears an optional field');
    }

    public function testAnImperialOwnersPatchLeavesEveryOtherStoredColumnAlone(): void
    {
        $owner = $this->createMember($this->app, 'imperial', new DisplayPreferences(
            'en_US',
            'America/New_York',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'USD',
        ));
        $car = $this->service($this->app, VehicleService::class)->create($owner, $this->golf->data);
        $api = $this->api($this->app, $this->apiKey($this->app, $owner));
        $ids = $this->garage($car);

        foreach ($ids as $list => $id) {
            $table = self::table($list);
            $before = $this->row($table, $id);
            $field = $list === 'odometer' || $list === 'expenses' ? 'note' : 'notes';
            if ($list === 'maintenance') {
                $field = 'description';
            }
            $response = $api->patch('/vehicles/' . $car->id . '/' . $list . '/' . $id, [$field => 'Checked']);
            self::assertSame(200, $response->getStatusCode(), $list . ': ' . self::body($response));
            $after = $this->row($table, $id);
            foreach ($before as $column => $value) {
                if (!in_array($column, [$field, 'updated_at'], true)) {
                    self::assertSame($value, $after[$column], $list . '.' . $column . ' round-trips untouched');
                }
            }
        }
    }

    public function testEachListEditsAndDeletesThroughItsForm(): void
    {
        $ids = $this->garage($this->golf);
        $base = '/vehicles/' . $this->golf->id;
        $changes = [
            'fuel' => [['odometer' => '40100', 'distance_unit' => 'km'], 'odometer', '40100.000'],
            'odometer' => [['odometer' => '41010', 'distance_unit' => 'km'], 'odometer', '41010.000'],
            'maintenance' => [['title' => 'Full service'], 'title', 'Full service'],
            'documents' => [['provider' => 'Garage'], 'provider', 'Garage'],
            'expenses' => [['amount' => '13'], 'amount', '13.000'],
            'trips' => [['to' => 'Larne'], 'to', 'Larne'],
            'incidents' => [['location' => 'Car park'], 'location', 'Car park'],
        ];
        foreach ($changes as $list => [$body, $field, $expected]) {
            $path = $base . '/' . $list . '/' . $ids[$list];
            $response = $this->api->patch($path, $body);
            self::assertSame(200, $response->getStatusCode(), $list . ': ' . self::body($response));
            self::assertSame($expected, ApiClient::json($response)->doc('entry')->get($field), $list);
            self::assertIsArray(ApiClient::json($response)->get('warnings'));
        }
        foreach ($ids as $list => $id) {
            $path = $base . '/' . $list . '/' . $id;
            $response = $this->api->delete($path);
            self::assertSame(204, $response->getStatusCode(), $list . ': ' . self::body($response));
            self::assertSame('', self::body($response));
            self::assertSame(404, $this->api->get($path)->getStatusCode(), $list . ' is gone');
            self::assertSame(404, $this->api->delete($path)->getStatusCode(), $list . ' twice');
        }
    }

    public function testATripKeepsItsWholeDistanceOnAReturn(): void
    {
        $ids = $this->garage($this->golf);
        $path = '/vehicles/' . $this->golf->id . '/trips/' . $ids['trips'];

        $entry = ApiClient::json($this->api->patch($path, ['notes' => 'School run']))->doc('entry');
        self::assertSame('90.500', $entry->get('distance_km'), 'never halved');
        $entry = ApiClient::json($this->api->patch($path, ['distance_km' => '100']))->doc('entry');
        self::assertSame('100.000', $entry->get('distance_km'), 'the whole trip, as on create');
        $refused = $this->api->patch($path, ['journey_id' => '1']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('api.validation.unknown_field', ApiClient::json($refused)->doc('errors')->doc('journey_id')->get('key'));
    }

    public function testValidationIsTheFormsAndUnknownFieldsAreRefused(): void
    {
        $ids = $this->garage($this->golf);
        $path = '/vehicles/' . $this->golf->id . '/fuel/' . $ids['fuel'];
        $tag = $this->api->get($path)->getHeaderLine('ETag');

        $required = $this->api->patch($path, ['filled_at' => null]);
        self::assertSame(422, $required->getStatusCode(), 'null on a required field is the form\'s required error');
        self::assertSame('validation_failed', ApiClient::json($required)->get('code'));
        self::assertArrayHasKey('filled_at', ApiClient::json($required)->doc('errors')->toArray());

        $unknown = $this->api->patch($path, ['colour' => 'red']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame('api.validation.unknown_field', ApiClient::json($unknown)->doc('errors')->doc('colour')->get('key'));

        $negative = $this->api->patch($path, ['volume' => '-1']);
        self::assertSame(422, $negative->getStatusCode());
        self::assertSame(400, $this->api->patch($path, '[1]')->getStatusCode());
        self::assertSame($tag, $this->api->get($path)->getHeaderLine('ETag'), 'nothing was written');
    }

    public function testOneAmountSentKeepsTheOtherTwoAsTheFormDoes(): void
    {
        $ids = $this->garage($this->golf);
        $path = '/vehicles/' . $this->golf->id . '/fuel/' . $ids['fuel'];
        $before = ApiClient::json($this->api->get($path));

        $entry = ApiClient::json($this->api->patch($path, ['total_cost' => '70']))->doc('entry');
        self::assertSame('70.000', $entry->get('total_cost'));
        self::assertSame($before->get('volume'), $entry->get('volume'), '#298');
        self::assertSame($before->get('price_per_unit'), $entry->get('price_per_unit'), '#298');
    }

    public function testAStaleIfMatchIsRefusedAndWritesNothing(): void
    {
        $ids = $this->garage($this->golf);
        $base = '/vehicles/' . $this->golf->id;
        foreach ($ids as $list => $id) {
            $path = $base . '/' . $list . '/' . $id;
            $tag = $this->api->get($path)->getHeaderLine('ETag');
            $body = ApiClient::json($this->api->get($path))->toArray();

            $stale = $this->api->patch($path, ['notes' => 'x'], ['If-Match' => '"0123456789abcdef0123456789abcdef"']);
            self::assertSame(412, $stale->getStatusCode(), $list);
            self::assertSame('precondition_failed', ApiClient::json($stale)->get('code'));
            self::assertSame(412, $this->api->delete($path, ['If-Match' => '"stale"'])->getStatusCode(), $list);
            self::assertSame($body, ApiClient::json($this->api->get($path))->toArray(), $list . ': nothing written');
            self::assertSame($tag, $this->api->get($path)->getHeaderLine('ETag'));

            self::assertSame(204, $this->api->delete($path, ['If-Match' => '"other", ' . $tag])->getStatusCode(), $list);
        }
    }

    public function testAMatchingIfMatchEdits(): void
    {
        $ids = $this->garage($this->golf);
        $path = '/vehicles/' . $this->golf->id . '/expenses/' . $ids['expenses'];
        $tag = $this->api->get($path)->getHeaderLine('ETag');

        $first = $this->api->patch($path, ['note' => 'Pay and display'], ['If-Match' => $tag]);
        self::assertSame(200, $first->getStatusCode());
        $second = $this->api->patch($path, ['note' => 'Again'], ['If-Match' => $tag]);
        self::assertSame(412, $second->getStatusCode(), 'the first edit changed the tag');
        self::assertSame(200, $this->api->patch($path, ['note' => 'Any'], ['If-Match' => '*'])->getStatusCode());
    }

    public function testADerivedReadingPointsAtTheEntryThatOwnsIt(): void
    {
        $ids = $this->garage($this->golf);
        $base = '/vehicles/' . $this->golf->id;
        $fill = $this->service($this->app, FuelService::class)->get($this->golf, $ids['fuel']);
        $derived = null;
        foreach ($this->service($this->app, OdometerService::class)->history($this->golf)->readings as $reading) {
            if ($reading->fuelEntryId === $fill->id) {
                $derived = $reading->id;
            }
        }
        self::assertNotNull($derived);

        foreach (['PATCH', 'DELETE'] as $method) {
            $response = $method === 'PATCH'
                ? $this->api->patch($base . '/odometer/' . $derived, ['note' => 'x'])
                : $this->api->delete($base . '/odometer/' . $derived);
            self::assertSame(409, $response->getStatusCode(), $method);
            $problem = ApiClient::json($response);
            self::assertSame('reading_derived', $problem->get('code'));
            self::assertSame($base . '/fuel/' . $fill->id, $problem->doc('links')->get('entry'));
        }
        self::assertSame(200, $this->api->get($base . '/odometer/' . $derived)->getStatusCode(), 'still there');
    }

    public function testAnArchivedVehicleRefusesEditsAndDeletes(): void
    {
        $ids = $this->garage($this->golf);
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        $base = '/vehicles/' . $this->golf->id;

        foreach ($ids as $list => $id) {
            $patch = $this->api->patch($base . '/' . $list . '/' . $id, ['notes' => 'x']);
            self::assertSame(409, $patch->getStatusCode(), $list);
            self::assertSame('vehicle_archived', ApiClient::json($patch)->get('code'));
            self::assertSame(409, $this->api->delete($base . '/' . $list . '/' . $id)->getStatusCode(), $list);
        }
    }

    public function testALogShareChangesItsOwnEntriesOnlyAndAReadKeyNothing(): void
    {
        $ids = $this->garage($this->golf);
        $base = '/vehicles/' . $this->golf->id;
        $driver = $this->createMember($this->app, 'driver');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $driver->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $driver));

        $forbidden = $theirs->patch($base . '/expenses/' . $ids['expenses'], ['note' => 'mine now']);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', ApiClient::json($forbidden)->get('code'));
        self::assertSame(403, $theirs->delete($base . '/expenses/' . $ids['expenses'])->getStatusCode());

        $own = ApiClient::json($theirs->post($base . '/expenses', ['category' => 'parking', 'amount' => '4']));
        $path = $base . '/expenses/' . $own->int('entry', 'id');
        self::assertSame(200, $theirs->patch($path, ['amount' => '5'])->getStatusCode());
        self::assertSame(204, $theirs->delete($path)->getStatusCode());

        $view = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $view->id, ShareLevel::View, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $viewer = $this->api($this->app, $this->apiKey($this->app, $view));
        self::assertSame(403, $viewer->patch($base . '/fuel/' . $ids['fuel'], ['notes' => 'x'])->getStatusCode());

        $reader = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        foreach ($ids as $list => $id) {
            $refused = $reader->patch($base . '/' . $list . '/' . $id, ['notes' => 'x']);
            self::assertSame('insufficient_scope', ApiClient::json($refused)->get('code'), $list);
            self::assertSame(403, $reader->delete($base . '/' . $list . '/' . $id)->getStatusCode(), $list);
        }

        $stranger = $this->createMember($this->app, 'stranger');
        $strangers = $this->api($this->app, $this->apiKey($this->app, $stranger));
        self::assertSame(404, $strangers->patch($base . '/fuel/' . $ids['fuel'], ['notes' => 'x'])->getStatusCode());
        self::assertSame(404, $strangers->delete($base . '/fuel/' . $ids['fuel'])->getStatusCode());
    }

    public function testAnotherVehiclesEntryIsNotFound(): void
    {
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $ids = $this->garage($fiesta);

        foreach ($ids as $list => $id) {
            self::assertSame(404, $this->api->patch('/vehicles/' . $this->golf->id . '/' . $list . '/' . $id, ['notes' => 'x'])
                ->getStatusCode(), $list);
            self::assertSame(404, $this->api->delete('/vehicles/' . $this->golf->id . '/' . $list . '/' . $id)
                ->getStatusCode(), $list);
        }
    }

    public function testAnEditAndADeleteLeaveTheDatabaseAsThePagesDo(): void
    {
        $browser = new TestBrowser($this->app);
        $browser->get('/login');
        self::assertSame(303, $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD])->getStatusCode());
        $preferences = $this->owner->preferences;

        // The same change through each: the API's field and the edit form's.
        $edits = [
            'fuel' => [
                ['odometer' => '40100', 'distance_unit' => 'km', 'volume' => '43'],
                ['odometer' => '40100', 'volume' => '43'],
            ],
            'odometer' => [['odometer' => '41200', 'distance_unit' => 'km'], ['reading' => '41200']],
            'maintenance' => [
                ['performed_on' => '2026-09-06', 'cost' => '190'],
                ['performed_on' => '2026-09-06', 'cost' => '190'],
            ],
            'documents' => [['expiry_on' => '2026-10-20'], ['expiry_on' => '2026-10-20']],
            'expenses' => [['amount' => '15', 'note' => 'Car park'], ['amount' => '15', 'note' => 'Car park']],
            'trips' => [['purpose' => 'Client visit'], ['purpose' => 'Client visit']],
            'incidents' => [['severity' => 'minor', 'location' => 'Lidl'], ['severity' => 'minor', 'location' => 'Lidl']],
        ];
        foreach (array_keys($edits) as $list) {
            [$api, $form] = $edits[$list];
            $byApi = $this->vehicle($this->app, 'Api', ucfirst($list));
            $byPage = $this->vehicle($this->app, 'Page', ucfirst($list));
            $apiIds = $this->garage($byApi);
            $pageIds = $this->garage($byPage);

            $response = $this->api->patch('/vehicles/' . $byApi->id . '/' . $list . '/' . $apiIds[$list], $api);
            self::assertSame(200, $response->getStatusCode(), $list . ': ' . self::body($response));
            $values = $this->pageValues($list, $byPage, $pageIds[$list], $preferences);
            $page = $browser->post('/vehicles/' . $byPage->id . '/' . $list . '/' . $pageIds[$list] . '/edit', $form + $values);
            self::assertSame(303, $page->getStatusCode(), $list . ' page edit: ' . self::body($page));
            self::assertSame($this->snapshot($byPage), $this->snapshot($byApi), $list . ': an edit leaves what the page leaves');

            $deleted = $this->api->delete('/vehicles/' . $byApi->id . '/' . $list . '/' . $apiIds[$list]);
            self::assertSame(204, $deleted->getStatusCode());
            $page = $browser->post('/vehicles/' . $byPage->id . '/' . $list . '/' . $pageIds[$list] . '/delete');
            self::assertSame(303, $page->getStatusCode(), $list . ' page delete');
            self::assertSame($this->snapshot($byPage), $this->snapshot($byApi), $list . ': a delete leaves what the page leaves');
        }
    }

    /**
     * The edit form as the page fills it for this entry.
     *
     * @return array<string, string|list<string>>
     */
    private function pageValues(string $list, Vehicle $vehicle, int $id, DisplayPreferences $preferences): array
    {
        return match ($list) {
            'fuel' => FuelEntryForm::values($this->service($this->app, FuelService::class)->get($vehicle, $id), $preferences),
            'odometer' => OdometerReadingForm::values(
                $this->service($this->app, OdometerService::class)->get($vehicle, $id),
                $preferences,
            ),
            'maintenance' => MaintenanceEntryForm::values(
                $this->service($this->app, MaintenanceService::class)->get($vehicle, $id),
                $preferences,
            ),
            'documents' => ComplianceDocumentForm::values(
                $this->service($this->app, ComplianceService::class)->get($vehicle, $id),
                $preferences,
            ),
            'expenses' => ExpenseEntryForm::values($this->service($this->app, ExpenseService::class)->get($vehicle, $id)),
            'trips' => TripForm::values(
                $this->service($this->app, TripService::class)->get($this->owner, $vehicle, $id),
                $preferences,
            ),
            'incidents' => (function () use ($vehicle, $id, $preferences): array {
                $incidents = $this->service($this->app, IncidentService::class);
                $incident = $incidents->get($vehicle, $id);

                return IncidentForm::values($incident, $incidents->odometerOf($vehicle, $incident), $preferences);
            })(),
            default => self::fail('no page for ' . $list),
        };
    }

    /**
     * Every row the vehicle owns in the tables an edit can touch, without
     * ids, links between rows and timestamps (which differ by vehicle).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(Vehicle $vehicle): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $query = $this->connection($this->app)->createQueryBuilder()->select('*')->from($table)
                ->where('vehicle_id = :vehicle');
            $rows = [];
            foreach ($query->setParameter('vehicle', $vehicle->id)->fetchAllAssociative() as $row) {
                $kept = [];
                foreach ($row as $column => $value) {
                    if ($column === 'id' || str_ends_with($column, '_id') || str_ends_with($column, '_at')) {
                        $kept[$column] = $value === null ? null : 'set';
                        continue;
                    }
                    $kept[$column] = is_scalar($value) ? (string) $value : null;
                }
                $rows[] = $kept;
            }
            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $id): array
    {
        $row = $this->connection($this->app)->createQueryBuilder()->select('*')->from($table)
            ->where('id = :id')->setParameter('id', $id)->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private static function table(string $list): string
    {
        return match ($list) {
            'fuel' => 'fuel_entries',
            'odometer' => 'odometer_readings',
            'maintenance' => 'maintenance_entries',
            'documents' => 'compliance_documents',
            'expenses' => 'expense_entries',
            'trips' => 'trips',
            'incidents' => 'incidents',
            default => self::fail('no table for ' . $list),
        };
    }
}
