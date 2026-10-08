<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueUpdateData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\TripRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Logbook\Tests\Support\VehicleRoutes;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * The access matrix (spec.md §5, §7.21): every vehicle and reminder route,
 * as the owner, a Manage share, a Log share with costs, a View share, a Log
 * share without costs, someone with no share, and an admin who is not the
 * owner. P = allowed (whatever the route then answers), F = 403, N = 404.
 * Entries in the fixture are the owner's, so under Log their edit and
 * delete are refused. A new route has to state its row here.
 */
final class AccessMatrixTest extends AppTestCase
{
    use CostFixtures;
    use VehicleRoutes;

    /** The columns of MATRIX. */
    private const array PEOPLE = ['owner', 'manage', 'log', 'view', 'log_no_costs', 'none', 'admin'];

    private const string VIEW = 'PPPPPNN';
    private const string COSTS = 'PPPFFNN';
    private const string LOG = 'PPPFPNN';
    /** Manage, and Log on someone else's entry (the own-entry rule). */
    private const string MANAGE = 'PPFFFNN';
    private const string OWN = 'PFFFFNN';
    /**
     * Another driver's trip (Phase 22): Manage and Own see it; a Log driver
     * does not even find it (404), and View may not log at all.
     */
    private const string OTHERS_TRIP = 'PPNFNNN';
    /**
     * Finance agreements (Phase 29.1, spec.md §7.32 *Access*): Manage with
     * costs only; everyone else finds nothing (404), not a refusal.
     */
    private const string FINANCE = 'PPNNNNN';

    /** Every module on, trips included (Phase 22: off by default). */
    private const array TRIPS_ON = ['FEATURES_TRIPS' => 'true'];

    /** @var array<string, string> route name => who may use it */
    private const array MATRIX = [
        'vehicles.show' => self::VIEW,
        'vehicles.edit' => self::MANAGE,
        'vehicles.first_inspection' => self::MANAGE,
        // Phase 24: Log may hide a check they could fix; the service judges which.
        'attention.hide' => self::LOG,
        'vehicles.delete' => self::OWN,
        'vehicles.archive' => self::OWN,
        'vehicles.restore' => self::OWN,
        'vehicles.photo' => self::VIEW,
        'vehicles.sharing' => self::VIEW,
        'vehicles.sharing.add' => self::OWN,
        'vehicles.sharing.change' => self::OWN,
        'vehicles.sharing.mine' => self::VIEW,
        'vehicles.transfer' => self::OWN,
        'history.vehicle' => self::VIEW,
        'history.print' => self::VIEW,
        'sale_pack.show' => self::MANAGE,
        'sale_pack.paperwork' => self::MANAGE,
        'odometer.index' => self::VIEW,
        'odometer.create' => self::LOG,
        'odometer.edit' => self::MANAGE,
        'odometer.delete' => self::MANAGE,
        'fuel.index' => self::VIEW,
        'fuel.create' => self::LOG,
        'fuel.edit' => self::MANAGE,
        'fuel.delete' => self::MANAGE,
        'fuel.economy' => self::LOG,
        'maintenance.index' => self::VIEW,
        'maintenance.create' => self::LOG,
        'maintenance.edit' => self::MANAGE,
        'maintenance.delete' => self::MANAGE,
        'maintenance.schedules.create' => self::MANAGE,
        'maintenance.schedules.edit' => self::MANAGE,
        'maintenance.schedules.delete' => self::MANAGE,
        'tyres.index' => self::VIEW,
        'tyres.change' => self::LOG,
        'tyres.changes.edit' => self::MANAGE,
        'tyres.changes.delete' => self::MANAGE,
        'tyres.sets.edit' => self::MANAGE,
        'tyres.sets.delete' => self::MANAGE,
        'tyres.edit' => self::MANAGE,
        'tyres.delete' => self::MANAGE,
        'compliance.index' => self::VIEW,
        'compliance.create' => self::LOG,
        'compliance.edit' => self::MANAGE,
        'compliance.delete' => self::MANAGE,
        'expenses.index' => self::VIEW,
        'expenses.create' => self::LOG,
        'expenses.edit' => self::MANAGE,
        'expenses.delete' => self::MANAGE,
        'trips.index' => self::VIEW,
        'trips.create' => self::LOG,
        'trips.edit' => self::OTHERS_TRIP,
        'trips.delete' => self::OTHERS_TRIP,
        // Incidents (Phase 27.1): the owner's, so Log may view but not change it.
        'incidents.index' => self::VIEW,
        'incidents.create' => self::LOG,
        'incidents.show' => self::VIEW,
        'incidents.edit' => self::MANAGE,
        'incidents.delete' => self::MANAGE,
        'incidents.links' => self::MANAGE,
        // Phase 40.1 (spec.md §7.37 *Access*): Log adds, watches, fixes and notes; the
        // owner's issue and note are someone else's under Log.
        'issues.index' => self::VIEW,
        'issues.create' => self::LOG,
        'issues.show' => self::VIEW,
        'issues.edit' => self::MANAGE,
        'issues.delete' => self::MANAGE,
        'issues.watch' => self::LOG,
        'issues.reopen' => self::LOG,
        'issues.fix' => self::LOG,
        'issues.updates.create' => self::LOG,
        'issues.updates.edit' => self::MANAGE,
        'issues.updates.delete' => self::MANAGE,
        'finance.index' => self::FINANCE,
        'finance.create' => self::FINANCE,
        'finance.show' => self::FINANCE,
        'finance.edit' => self::FINANCE,
        'finance.delete' => self::FINANCE,
        'finance.payments' => self::FINANCE,
        'finance.events.delete' => self::FINANCE,
        'finance.quotes' => self::FINANCE,
        'finance.quotes.delete' => self::FINANCE,
        'finance.schedule' => self::FINANCE,
        'finance.end' => self::FINANCE,
        'valuations.index' => self::COSTS,
        // Phase 33.4: the Cost of ownership tab.
        'vehicles.ownership' => self::COSTS,
        'valuations.create' => self::MANAGE,
        'valuations.edit' => self::MANAGE,
        'valuations.delete' => self::MANAGE,
        'export.module' => self::MANAGE,
        'import.upload' => self::MANAGE,
        'import.map' => self::MANAGE,
        'attachments.show' => self::VIEW,
        'attachments.delete' => self::MANAGE,
        'reminders.edit' => self::MANAGE,
        'reminders.delete' => self::MANAGE,
        'reminders.status' => self::LOG,
    ];

    public function testEveryVehicleRouteHasARow(): void
    {
        $names = array_map(
            static fn (RouteInterface $route): string => (string) $route->getName(),
            $this->routes($this->createApp(self::TRIPS_ON)),
        );
        sort($names);
        $listed = array_keys(self::MATRIX);
        sort($listed);

        self::assertSame($listed, $names, "Give each vehicle or reminder route its row in AccessMatrixTest::MATRIX.");
    }

    public function testEachPersonGetsWhatTheirAccessAllows(): void
    {
        $app = $this->createApp(self::TRIPS_ON);
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        [$vehicle, $ids, $browsers] = $this->fixture($app);
        $readOnly = [];
        foreach ($this->routes($app) as $route) {
            // Routes that only POST change something: each gets a fresh fixture below.
            if (in_array('GET', $route->getMethods(), true)) {
                $readOnly[] = $route;
            }
        }

        $wrong = [];
        foreach ($readOnly as $route) {
            $wrong = [...$wrong, ...$this->check($route, $vehicle, $ids, $browsers)];
        }
        foreach ($this->routes($app) as $route) {
            if (in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            foreach (self::PEOPLE as $i => $person) {
                $fresh = $this->createApp(self::TRIPS_ON);
                $this->pinClock($fresh, '2026-09-30T12:00:00Z');
                [$v, $freshIds, $freshBrowsers] = $this->fixture($fresh);
                $wrong = [...$wrong, ...$this->check($route, $v, $freshIds, [$person => $freshBrowsers[$person]], $i)];
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, TestBrowser> $browsers
     * @return list<string> what differed
     */
    private function check(RouteInterface $route, Vehicle $vehicle, array $ids, array $browsers, ?int $only = null): array
    {
        $name = (string) $route->getName();
        $expected = self::MATRIX[$name] ?? '';
        $wrong = [];
        foreach ($browsers as $person => $browser) {
            $column = $only ?? array_search($person, self::PEOPLE, true);
            $want = $expected[(int) $column] ?? '?';
            $status = $this->requestFor($browser, $route, $vehicle, $ids)->getStatusCode();
            $got = match ($status) {
                403 => 'F',
                404 => $want === 'P' ? 'P' : 'N',
                default => 'P',
            };
            if ($got !== $want) {
                $wrong[] = sprintf('%s as %s: expected %s, got %d', $name, $person, $want, $status);
            }
        }

        return $wrong;
    }

    /**
     * The owner's vehicle with one of everything (all the owner's), and a
     * signed-in browser for each person.
     *
     * @param App<ContainerInterface> $app
     * @return array{Vehicle, array<string, int>, array<string, TestBrowser>}
     */
    private function fixture(App $app): array
    {
        $browsers = ['owner' => $this->signedIn($app)];
        $owner = $this->owner($app);
        $vehicle = $this->vehicle($app);
        $day = static fn (string $date): DateTimeImmutable => new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $db = $this->connection($app);

        $fill = $this->fillUp($app, $vehicle, '2026-09-01T08:00:00Z', '10000', '40', '60.00');
        $reading = $this->service($app, OdometerService::class)
            ->create($vehicle, new OdometerReadingData('10100', $day('2026-09-05T08:00:00Z')));
        $service = $this->maintenance($app, $vehicle, '2026-09-06', 'Service', '100.00');
        $schedule = $this->service($app, ScheduleService::class)->create($vehicle, new MaintenanceScheduleData(
            category: MaintenanceCategory::Service,
            title: 'Annual service',
            intervalMonths: 12,
            baselineDoneOn: $day('2026-01-01'),
        ));
        $document = $this->document($app, $vehicle, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '200.00');
        $expense = $this->expense($app, $vehicle, '2026-09-12', '12.00', ExpenseCategory::Parking);
        $valuation = $this->service($app, ValuationService::class)
            ->create($vehicle, new VehicleValuationData($day('2026-09-15'), '9000.00'));
        $trip = $this->service($app, TripRepository::class)->insert($vehicle->id, new TripData(
            travelledOn: $day('2026-09-10'),
            fromPlace: 'Ballymena',
            toPlace: 'Belfast',
            distanceKm: '86.905',
            purpose: 'Client meeting',
        ), new DateTimeImmutable('2026-09-10T18:00:00Z'), $owner->id);
        $reminder = $this->service($app, ReminderService::class)
            ->createManual($owner, new ManualReminderData($vehicle->id, 'Wash', $day('2026-10-10'), 7));
        $incident = $this->service($app, IncidentService::class)->create(
            $vehicle,
            new IncidentData($day('2026-09-08'), IncidentType::ParkedDamage),
            null,
            new DateTimeZone('Europe/London'),
        );
        $issues = $this->service($app, IssueService::class);
        $issue = $issues->create($vehicle, new IssueData($day('2026-09-08'), 'Knock'), new DateTimeZone('Europe/London'));
        $still = new IssueUpdateData($day('2026-09-09'), 'Still there');
        $note = $issues->addUpdate($vehicle, $issue, $still, new DateTimeZone('Europe/London'));
        $agreement = $this->service($app, FinanceAgreementRepository::class)->insert($vehicle->id, new AgreementData(
            type: AgreementType::Hp,
            lender: 'Black Horse',
            agreementNumber: null,
            startedOn: $day('2026-01-15'),
            firstPaymentOn: $day('2026-02-15'),
            numberOfPayments: 36,
            regularPayment: '300',
            cashPrice: '10000',
        ), new DateTimeImmutable('2026-09-01T00:00:00Z'), $owner->id);
        $finance = $this->service($app, FinanceAgreementRepository::class);
        $event = $finance->insertEvent(
            $agreement,
            PaymentEventKind::Missed,
            $day('2026-03-15'),
            null,
            null,
            null,
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $quote = $finance->insertQuote(
            $agreement,
            $day('2026-09-01'),
            '7000',
            $day('2026-09-30'),
            null,
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $now = '2026-09-01 00:00:00';
        $db->insert('tyre_sets', ['vehicle_id' => $vehicle->id, 'name' => 'Winters', 'created_at' => $now, 'updated_at' => $now]);
        $set = (int) $db->lastInsertId();
        $db->insert('tyres', ['vehicle_id' => $vehicle->id, 'status' => 'stored', 'created_at' => $now, 'updated_at' => $now]);
        $tyre = (int) $db->lastInsertId();
        $db->insert('tyre_changes', [
            'vehicle_id' => $vehicle->id, 'kind' => 'check', 'done_on' => '2026-09-02', 'odometer_km' => '10050.000',
            'created_by' => $owner->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $change = (int) $db->lastInsertId();
        $db->insert('attachments', [
            'vehicle_id' => $vehicle->id, 'owner_type' => 'fuel', 'owner_id' => $fill->id, 'filename' => 'receipt.png',
            'mime' => 'image/png', 'size' => 1, 'stored_path' => 'attachments/receipt.png', 'uploaded_at' => $now,
            'uploaded_by' => $owner->id,
        ]);
        $attachment = (int) $db->lastInsertId();

        $shares = $this->service($app, VehicleShareRepository::class);
        $at = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $people = [
            'manage' => [ShareLevel::Manage, true],
            'log' => [ShareLevel::Log, true],
            'view' => [ShareLevel::View, false],
            'log_no_costs' => [ShareLevel::Log, false],
        ];
        $memberIds = [];
        foreach ($people as $person => [$level, $costs]) {
            $member = $this->createMember($app, str_replace('_', '', $person));
            $memberIds[$person] = $member->id;
            $shares->insert($vehicle->id, $member->id, $level, $costs, false, $at);
        }
        $this->createMember($app, 'none');
        $this->createMember($app, 'admin', isAdmin: true);
        foreach ([...array_keys($people), 'none', 'admin'] as $person) {
            $browsers[$person] = $this->browserFor($app, str_replace('_', '', $person));
        }

        $ids = [
            'reading' => $reading->id,
            'schedule' => $schedule->id,
            'document' => $document->id,
            'change' => $change,
            'set' => $set,
            'tyre' => $tyre,
            'attachment' => $attachment,
            'reminder' => $reminder->id,
            'incident' => $incident->id,
            'issue' => $issue->id,
            'update' => $note->id,
            'agreement' => $agreement,
            'event' => $event,
            'quote' => $quote,
            'member' => $memberIds['view'] ?? 0,
            // {entry} is a different kind per route: see requestFor().
            'entry' => $fill->id,
        ];
        $this->entryIds = [
            'fuel' => $fill->id,
            'maintenance' => $service->id,
            'expenses' => $expense->id,
            'valuations' => $valuation->id,
            'trips' => $trip,
        ];

        return [$vehicle, $ids, $browsers];
    }

    /** @var array<string, int> route name prefix => the {entry} of that kind */
    private array $entryIds = [];

    /**
     * requestRoute(), with the {entry} of each route of its own kind.
     *
     * @param array<string, int> $ids
     */
    private function requestFor(TestBrowser $browser, RouteInterface $route, Vehicle $vehicle, array $ids): ResponseInterface
    {
        $prefix = explode('.', (string) $route->getName())[0];

        return $this->requestRoute($browser, $route, $vehicle, ['entry' => $this->entryIds[$prefix] ?? $ids['entry']] + $ids);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<RouteInterface>
     */
    private function routes(App $app): array
    {
        return array_values(array_filter(
            $app->getRouteCollector()->getRoutes(),
            static fn (RouteInterface $route): bool => $route->getArgument('ability') !== null
                && !str_starts_with($route->getPattern(), '/api/'),
        ));
    }
}
