<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Incident;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Service\Incident\ClaimsHistory;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreCost;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Incidents through the service against a real database (spec.md §7.29):
 * links that never double-count, the reading, the insurer default, closing,
 * deleting, and the claims history.
 */
final class IncidentServiceTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $golf;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->golf = $this->vehicle($this->app);
    }

    private function incidents(): IncidentService
    {
        return $this->service($this->app, IncidentService::class);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    private function log(
        string $date = '2026-03-14',
        IncidentType $type = IncidentType::ParkedDamage,
        Claim $claim = new Claim(),
        ?Vehicle $vehicle = null,
        ?string $odometerKm = null,
        Fault $fault = Fault::NotAtFault,
        ?int $driverUserId = null,
        ?string $driverName = null,
    ): Incident {
        return $this->incidents()->create($vehicle ?? $this->golf, new IncidentData(
            occurredOn: self::day($date),
            type: $type,
            fault: $fault,
            damageAreas: [DamageArea::Rear],
            driverUserId: $driverUserId,
            driverName: $driverName,
            otherPartyName: 'A. Driver',
            claim: $claim,
        ), $odometerKm, self::zone());
    }

    private function spend(): string
    {
        $report = $this->service($this->app, ReportService::class)->build(
            $this->owner,
            new ReportFilter(ReportPeriod::preset(ReportRange::AllTime, self::day('2026-09-29'))),
        );

        return $report->currencies[0]->total->toDecimal(2);
    }

    public function testALinkedRepairCountsOnceAndUnlinkingChangesNothingInReports(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00');
        $this->expense($this->app, $this->golf, '2026-03-21', '25.00', ExpenseCategory::Other, 'Hire car');
        $before = $this->spend();
        self::assertSame('1425.00', $before);

        $incident = $this->log(claim: new Claim(ClaimStatus::Settled, payout: '1000.000', excess: '0.000'));
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);

        $costs = $this->incidents()->costs($this->owner, $this->golf, $incident);
        self::assertSame('1400.00', $costs->linked->toDecimal(2));
        self::assertSame('1000.00', $costs->payouts->toDecimal(2));
        self::assertSame('400.00', $costs->net->toDecimal(2));
        self::assertFalse($costs->receivedMore);
        self::assertSame($before, $this->spend(), 'Reports count the repair once, under maintenance');

        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, null);
        self::assertSame($before, $this->spend(), 'unlinking changes nothing in Reports');
        self::assertSame('0.00', $this->incidents()->costs($this->owner, $this->golf, $incident)->linked->toDecimal(2));
    }

    public function testAPayoutAboveTheLinkedCostsShowsNothingBelowZero(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Scratch', '300.00');
        $incident = $this->log(claim: new Claim(ClaimStatus::Settled, payout: '450.000'));
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);

        $costs = $this->incidents()->costs($this->owner, $this->golf, $incident);
        self::assertSame('0.00', $costs->net->toDecimal(2));
        self::assertTrue($costs->receivedMore);
    }

    public function testAZeroExcessIsKept(): void
    {
        $incident = $this->log(claim: new Claim(ClaimStatus::Open, excess: '0.000'));

        self::assertSame('0.000', $incident->data->claim->excess);
    }

    public function testATyreChangeLinkedToItsServiceRecordFollowsTheRecord(): void
    {
        $tyre = new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'));
        $change = $this->service($this->app, TyreChangeService::class)->fit(
            $this->golf,
            new TyreChangeData(self::day('2026-03-22'), '12000.000'),
            [$tyre],
            [],
            new TyreCost('120.000', 'Kwik Fit'),
            self::zone(),
            'en_GB',
        );
        $record = $change->data->maintenanceEntryId;
        self::assertNotNull($record);
        $incident = $this->log(type: IncidentType::Pothole);

        $this->incidents()->link($this->golf, LinkKind::Maintenance, $record, $incident);
        $links = $this->incidents()->links($this->golf)[$incident->id];
        self::assertSame([$record], $links['maintenance']);
        self::assertSame([$change->id], $links['tyre'], 'the change follows its record');
        self::assertSame(
            '120.00',
            $this->incidents()->costs($this->owner, $this->golf, $incident)->linked->toDecimal(2),
            'the tyre cost counts once, as its record',
        );

        $this->incidents()->link($this->golf, LinkKind::Maintenance, $record, null);
        self::assertArrayNotHasKey($incident->id, $this->incidents()->links($this->golf));
        $found = $this->service($this->app, TyreRepository::class)->findChange($this->golf->id, $change->id);
        self::assertNull($found?->incidentId);
    }

    public function testDeletingAnIncidentUnlinksAndKeepsItsRecordsAndDeletingARecordKeepsTheIncident(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00');
        $other = $this->maintenance($this->app, $this->golf, '2026-03-25', 'Paint', '200.00');
        $incident = $this->log();
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $other->id, $incident);

        $this->service($this->app, MaintenanceService::class)->delete($this->golf, $other, self::zone());
        self::assertSame($incident->id, $this->incidents()->get($this->golf, $incident->id)->id);

        $this->incidents()->delete($this->golf, $incident);
        $kept = $this->service($this->app, MaintenanceEntryRepository::class)->find($this->golf->id, $repair->id);
        self::assertNotNull($kept, 'the record is kept');
        self::assertNull($kept->incidentId, 'and unlinked');
    }

    public function testTheOdometerWritesAnIncidentReadingThatMovesAndGoes(): void
    {
        $incident = $this->incidents()->create($this->golf, new IncidentData(
            occurredOn: self::day('2026-03-14'),
            type: IncidentType::Collision,
            occurredAtTime: '08:15',
        ), '42000.000', self::zone());
        $readings = $this->service($this->app, OdometerReadingRepository::class);
        $reading = $readings->findByEntry($this->golf->id, OdometerSource::Incident, $incident->id);
        self::assertNotNull($reading);
        self::assertSame('2026-03-14T08:15:00+00:00', $reading->recordedAt->format('c'), 'its local time (GMT in March)');
        self::assertSame('42000.000', $this->incidents()->odometerOf($this->golf, $incident));

        $this->incidents()->update($this->golf, $incident, $incident->data, null, self::zone());
        self::assertNull($readings->findByEntry($this->golf->id, OdometerSource::Incident, $incident->id));
    }

    public function testTheInsurerDefaultsToThePolicyCurrentOnTheIncidentDate(): void
    {
        $this->document($this->app, $this->golf, ComplianceType::Insurance, '2025-01-01', '2025-12-31', '500.00', 'Aviva');
        $this->document($this->app, $this->golf, ComplianceType::Insurance, '2026-01-01', '2026-12-31', '520.00', 'Direct Line');

        self::assertSame('Aviva', $this->incidents()->policyOn($this->golf, self::day('2025-06-01'))?->data->provider);
        self::assertSame('Direct Line', $this->incidents()->policyOn($this->golf, self::day('2026-03-14'))?->data->provider);
        self::assertNull($this->incidents()->policyOn($this->golf, self::day('2024-06-01')));
    }

    public function testClosingSetsTodayAndAChangedClaimStatusMovesTheLatestUpdate(): void
    {
        $incident = $this->log(claim: new Claim(ClaimStatus::Notified, updatedOn: self::day('2026-04-01')));
        $closed = $this->incidents()->update($this->golf, $incident, new IncidentData(
            occurredOn: $incident->data->occurredOn,
            type: $incident->data->type,
            status: IncidentStatus::Closed,
            claim: new Claim(ClaimStatus::Settled, updatedOn: self::day('2026-04-01')),
        ), null, self::zone());

        self::assertSame('2026-09-29', $closed->data->closedOn?->format('Y-m-d'));
        self::assertSame('2026-09-29', $closed->data->claim->updatedOn?->format('Y-m-d'), 'the status changed, the date did not');

        $reopened = $this->incidents()->update($this->golf, $closed, new IncidentData(
            occurredOn: $incident->data->occurredOn,
            type: $incident->data->type,
            claim: new Claim(ClaimStatus::Settled, updatedOn: self::day('2026-05-02')),
        ), null, self::zone());
        self::assertNull($reopened->data->closedOn);
        self::assertSame('2026-05-02', $reopened->data->claim->updatedOn?->format('Y-m-d'), 'a date given is kept');
    }

    public function testTheClaimsHistoryIncludesSoldVehiclesAndCountsFiveYearsByCalendarDate(): void
    {
        $old = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $this->log('2021-09-29', IncidentType::Collision, new Claim(ClaimStatus::Settled, 'Aviva'), $old, fault: Fault::AtFault);
        $this->log('2021-09-28', IncidentType::Glass, vehicle: $old);
        $this->service($this->app, VehicleService::class)->archive($this->owner, $old);
        $this->log('2026-03-14');

        $history = $this->service($this->app, ClaimsHistory::class);
        $rows = $history->report($this->owner)->rows;
        self::assertSame(['2026-03-14', '2021-09-29'], array_map(
            static fn ($row): string => $row->incident->occurredOn->format('Y-m-d'),
            $rows,
        ), 'five years back to the same calendar day, the sold car included');
        self::assertSame('Fiesta', $rows[1]->vehicle->data->model);

        $claims = $history->report($this->owner, new ClaimsFilter(claimsOnly: true))->rows;
        self::assertCount(1, $claims);
        self::assertSame(Fault::AtFault, $claims[0]->incident->fault);

        self::assertCount(3, $history->report($this->owner, new ClaimsFilter(years: 10))->rows);
    }

    public function testTheDriverFilterMatchesAUserOrATypedName(): void
    {
        $partner = $this->createMember($this->app);
        $this->log('2026-01-10', driverUserId: $partner->id);
        $this->log('2026-02-10', driverName: 'Alex');
        $this->log('2026-03-10');

        $history = $this->service($this->app, ClaimsHistory::class);
        $report = $history->report($this->owner);
        self::assertSame(['name:Alex' => 'Alex', (string) $partner->id => 'Sam Partner'], $report->drivers, 'by name');
        $rows = $history->report($this->owner, new ClaimsFilter(driver: (string) $partner->id))->rows;
        self::assertCount(1, $rows);
        self::assertSame('Sam Partner', $rows[0]->driver);
        self::assertCount(1, $history->report($this->owner, new ClaimsFilter(driver: 'name:Alex'))->rows);
    }

    public function testDetailsAreForManageOwnAndTheCreatorOnly(): void
    {
        $viewer = $this->createMember($this->app, 'viewer', displayName: 'Viv Viewer');
        $logger = $this->createMember($this->app, 'logger', displayName: 'Lou Logger');
        $manager = $this->createMember($this->app, 'manager', displayName: 'Max Manager');
        $shares = $this->service($this->app, VehicleShareRepository::class);
        $now = new DateTimeImmutable('2026-09-29T10:00:00Z');
        $shares->insert($this->golf->id, $viewer->id, ShareLevel::View, true, false, $now);
        $shares->insert($this->golf->id, $logger->id, ShareLevel::Log, false, false, $now);
        $shares->insert($this->golf->id, $manager->id, ShareLevel::Manage, false, false, $now);

        $byOwner = $this->log(claim: new Claim(ClaimStatus::Open, 'Aviva', claimNumber: '4417', payout: '10.000'));
        $context = $this->service($this->app, AccessContext::class);
        $context->apply($logger);
        $byLogger = $this->log('2026-04-01');
        $context->apply(null);

        $access = $this->service($this->app, IncidentAccess::class);
        foreach ([[$this->owner, true], [$manager, true], [$logger, false], [$viewer, false]] as [$user, $sees]) {
            $view = $access->view($user, $this->golf, $byOwner);
            self::assertSame($sees, $view->details, $user->username);
            self::assertSame($sees ? '4417' : null, $view->claimNumber, $user->username);
            self::assertSame($sees ? 'A. Driver' : null, $view->otherPartyName, $user->username);
            self::assertSame($sees ? Fault::NotAtFault : null, $view->fault, $user->username);
            self::assertSame([DamageArea::Rear], $view->damageAreas, 'everyone sees the damage');
        }
        self::assertTrue($access->view($logger, $this->golf, $byLogger)->details, 'the creator sees their own');
        self::assertNull($access->view($logger, $this->golf, $byOwner)->payout);
        self::assertSame('10.000', $access->view($manager, $this->golf, $byOwner)->payout);
        self::assertFalse($access->view($logger, $this->golf, $byOwner)->amounts, 'amounts follow ViewCosts');
        self::assertTrue($access->view($viewer, $this->golf, $byOwner)->amounts, 'a View share with costs');
    }
}
