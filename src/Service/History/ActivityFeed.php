<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Incident;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\PartRepository;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\PartFitting;
use Logbook\Repository\MotTestRepository;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotReview;
use Logbook\Service\User\UserDirectory;
use Logbook\Domain\Issue\Issue;
use Logbook\Repository\IncidentRepository;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ActivityDateRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\DatedSource;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\TripRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSummary;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Units\DepthUnit;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The one list of entries across modules (spec.md §7.16): read by the
 * dashboard's *Recent activity*, the History tab and the fleet history.
 * Nothing else lists entries across modules.
 *
 * - Newest first by the owner's local date, then by when the entry was added
 *   (ActivityItem::compare). Fill-ups and readings are instants and the rest
 *   calendar dates, so everything is placed on the owner's calendar first.
 * - Readings written by a fill-up, service, document or tyre change are left
 *   out (the entry itself is listed); so are a switched-off module's entries.
 * - A tyre change linked to a service record is never listed on its own:
 *   the record's row carries its summary (a second line) and is listed under
 *   the Tyres kind too. With maintenance off the change is listed itself.
 * - A range bounds every query: fill-ups and readings by the UTC instants of
 *   its local start and end, services and expenses by date. Documents are
 *   few, and "dated by its start, else the day it was added" needs the
 *   owner's time zone, so they are read per vehicle and placed in PHP.
 *   Milestones come from the vehicle rows. Valuations are dated by
 *   `valued_on` and carry their amount as a price, never as a cost.
 * - Attachment counts come from one grouped query for the items read.
 *
 * Callers pass vehicles already resolved for the signed-in owner.
 */
final readonly class ActivityFeed
{
    /** The dashboard widget's length. */
    public const int LATEST = 8;

    public function __construct(
        private FuelEntryRepository $fuel,
        private OdometerReadingRepository $readings,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private ActivityDateRepository $dates,
        private AttachmentService $attachments,
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private TyreRepository $tyres,
        private ValuationRepository $valuations,
        private TripRepository $trips,
        private VehicleAccess $access,
        private IncidentRepository $incidents,
        private IssueRepository $issues,
        private MotTestRepository $motTests,
        private MotHistoryConfig $motHistory,
        private UserDirectory $users,
        private ModificationRepository $modifications,
        private PartRepository $parts,
    ) {
    }

    /**
     * @return list<ActivityItem> newest first
     */
    public function items(User $user, ActivityQuery $query): array
    {
        $query = $this->enabledOnly($query);
        $documents = $this->documentsOf($query);

        return $this->read($user, $query, $documents, $this->motTestsOf($query, $documents));
    }

    /**
     * The year page $requested (the newest year with anything when it is
     * null or outside the years that have anything), with its neighbours.
     *
     * @param list<Vehicle> $vehicles
     * @param list<ActivityKind> $kinds
     */
    public function year(User $user, array $vehicles, array $kinds, ?int $requested): FeedYear
    {
        $query = $this->enabledOnly(new ActivityQuery($vehicles, $kinds));
        $documents = $this->documentsOf($query);
        $tests = $this->motTestsOf($query, $documents);
        $zone = $user->preferences->timeZone();
        [$first, $newest] = $this->span($query, $documents, $tests, $zone);
        if ($first === null || $newest === null) {
            return new FeedYear(null);
        }

        $year = $requested !== null && $requested >= $first && $requested <= $newest ? $requested : $newest;
        $page = ActivityQuery::year($query->vehicles, $query->kinds, $year);

        return new FeedYear(
            $year,
            $this->read($user, $page, $documents, $tests),
            $this->newer($query, $documents, $tests, $zone, $year),
            $this->older($query, $documents, $tests, $zone, $year),
            $first,
            $newest,
        );
    }

    /**
     * The latest entries (no milestones): the newest year pages, as many as
     * it takes.
     *
     * @param list<Vehicle> $vehicles
     * @return list<ActivityItem> newest first
     */
    public function latest(User $user, array $vehicles, int $limit = self::LATEST): array
    {
        // Not MOT tests (spec.md §7.38): DVSA's data shows with its attribution, on History.
        $kinds = array_values(array_filter(
            ActivityKind::entries(),
            static fn (ActivityKind $kind): bool => $kind !== ActivityKind::MotTest,
        ));
        $query = $this->enabledOnly(new ActivityQuery($vehicles, $kinds));
        $documents = $this->documentsOf($query);
        $tests = $this->motTestsOf($query, $documents);
        $zone = $user->preferences->timeZone();
        $year = $this->span($query, $documents, $tests, $zone)[1];

        $items = [];
        while ($year !== null && count($items) < $limit) {
            $page = ActivityQuery::year($query->vehicles, $query->kinds, $year);
            array_push($items, ...$this->read($user, $page, $documents, $tests));
            $year = $this->older($query, $documents, $tests, $zone, $year);
        }

        return array_slice($items, 0, $limit);
    }

    private function enabledOnly(ActivityQuery $query): ActivityQuery
    {
        $enabled = $this->features->all();
        $kinds = array_values(array_filter(
            $query->kinds,
            static fn (ActivityKind $kind): bool => $kind->feature() === null || $enabled[$kind->feature()->value],
        ));

        return new ActivityQuery($query->vehicles, $kinds, $query->from, $query->until, $query->limit);
    }

    /**
     * @return list<ComplianceDocument>
     */
    private function documentsOf(ActivityQuery $query): array
    {
        return $query->includes(ActivityKind::Document) ? $this->documents->listForVehicles($query->vehicleIds()) : [];
    }

    /**
     * The MOT tests listed on their own (spec.md §7.38 *History*): none
     * while MOT history is off, and never one that became an `inspection`
     * document, whose line carries it.
     *
     * @param list<ComplianceDocument> $documents the page's documents, when it lists them
     * @return list<MotTest>
     */
    private function motTestsOf(ActivityQuery $query, array $documents = []): array
    {
        if (!$query->includes(ActivityKind::MotTest) || !$this->motHistory->enabled()) {
            return [];
        }
        // History shows how many defects a test had, not what they said.
        $tests = $this->motTests->summariesForVehicles($query->vehicleIds());
        if ($tests === []) {
            return [];
        }
        // The documents already read for the page when they are listed too.
        $documents = $documents !== [] ? $documents : $this->documents->listForVehicles($query->vehicleIds());
        // "Became a document" is judged on the owner's calendar, as the review card dates it.
        $zones = [];
        foreach ($query->vehicles as $vehicle) {
            $zones[$vehicle->id] = $this->users->find($vehicle->userId)?->preferences->timeZone() ?? new DateTimeZone('UTC');
        }

        return array_values(array_filter(
            $tests,
            static fn (MotTest $test): bool => MotReview::match($documents, $test, $zones[$test->vehicleId]) === null,
        ));
    }

    /**
     * @param list<ComplianceDocument> $documents every document of the query's vehicles
     * @param list<MotTest> $tests the MOT tests listed on their own
     * @return list<ActivityItem> newest first
     */
    private function read(User $user, ActivityQuery $query, array $documents, array $tests): array
    {
        $zone = $user->preferences->timeZone();
        $ids = $query->vehicleIds();
        $from = $query->from === null ? null : self::startOf($query->from, $zone);
        $until = $query->until === null ? null : self::startOf($query->until, $zone);

        $fills = $query->includes(ActivityKind::Fuel) ? $this->fuel->listForVehiclesBetween($ids, $from, $until) : [];
        $readings = $query->includes(ActivityKind::Odometer)
            ? $this->readings->listManualForVehiclesBetween($ids, $from, $until)
            : [];
        $enabled = $this->features->all();
        $maintenanceOn = $enabled[Feature::Maintenance->value];
        $tyresOn = $enabled[Feature::Tyres->value];
        $withTyres = $query->includes(ActivityKind::Tyre);
        // The Tyres kind lists linked service records too, so read them when either is asked for.
        $services = $query->includes(ActivityKind::Maintenance) || ($withTyres && $maintenanceOn)
            ? $this->maintenance->listForVehiclesBetween($ids, $query->from, $query->until)
            : [];
        $changes = $withTyres || ($tyresOn && $services !== [])
            ? $this->tyres->listChangesBetween($ids, $query->from, $query->until)
            : [];
        [$ownChanges, $linkedTo] = $this->splitLinked($changes, $services, $maintenanceOn);
        if (!$query->includes(ActivityKind::Maintenance)) {
            $services = array_values(array_filter(
                $services,
                static fn (MaintenanceEntry $e): bool => isset($linkedTo[$e->id]),
            ));
        }
        if (!$withTyres) {
            $ownChanges = [];
        }
        $summaries = $this->tyreSummaries($ids, $changes, $user->preferences->depthUnit);
        $expenses = $query->includes(ActivityKind::Expense)
            ? $this->expenses->listForVehiclesBetween($ids, $query->from, $query->until)
            : [];
        $documents = array_values(array_filter(
            $documents,
            static fn (ComplianceDocument $d): bool => $query->covers(self::documentDate($d, $zone)),
        ));
        $tests = array_values(array_filter(
            $tests,
            static fn (MotTest $test): bool => $query->covers(LocalTime::dateOf($test->completedAt, $zone)),
        ));
        $valuations = $query->includes(ActivityKind::Valuation)
            ? $this->valuations->listForVehiclesBetween($ids, $query->from, $query->until)
            : [];
        // Another driver's trips only for those who may see them (spec.md §7.22).
        $trips = [];
        if ($query->includes(ActivityKind::Trip)) {
            $everyone = [];
            foreach ($query->vehicles as $vehicle) {
                $everyone[$vehicle->id] = $this->access->can($user, VehicleAbility::ViewOthersTrips, $vehicle);
            }
            $trips = array_values(array_filter(
                $this->trips->listForVehiclesBetween($ids, $query->from, $query->until),
                static fn (Trip $trip): bool => $trip->createdBy === $user->id || ($everyone[$trip->vehicleId] ?? false),
            ));
        }

        // Incidents (Phase 27.1): their rows, and the "Part of" note on the records they link.
        $incidentsOn = $enabled[Feature::Incidents->value];
        $incidentsOf = [];
        if ($incidentsOn) {
            // Each vehicle's incidents are read once per request, all in one query.
            $this->incidents->prime($ids);
            foreach ($ids as $id) {
                foreach ($this->incidents->listForVehicle($id) as $incident) {
                    $incidentsOf[$incident->id] = $incident;
                }
            }
        }
        $incidents = $query->includes(ActivityKind::Incident)
            ? array_values(array_filter($incidentsOf, static fn (Incident $i): bool => $query->covers($i->data->occurredOn)))
            : [];
        $linkedSummaries = $this->incidents->linkedSummaries(array_map(static fn (Incident $i): int => $i->id, $incidents));
        $partOf = static fn (?int $incidentId): ?Incident => $incidentId === null ? null : ($incidentsOf[$incidentId] ?? null);

        // Issues (Phase 40.1): noticed on their date and, once fixed, fixed on theirs.
        $noticed = [];
        $fixedIssues = [];
        if ($query->includes(ActivityKind::IssueNoticed) || $query->includes(ActivityKind::IssueFixed)) {
            foreach ($this->issues->listTouching($ids, $query->from, $query->until) as $issue) {
                if ($query->includes(ActivityKind::IssueNoticed) && $query->covers($issue->data->noticedOn)) {
                    $noticed[] = $issue;
                }
                $fixed = $issue->isFixed() && $issue->fixedOn !== null && $query->covers($issue->fixedOn);
                if ($query->includes(ActivityKind::IssueFixed) && $fixed) {
                    $fixedIssues[] = $issue;
                }
            }
        }
        $fixSummaries = $this->issues->fixSummaries(array_map(static fn (Issue $i): int => $i->id, $fixedIssues));

        // Parts and modifications (Phase 50.1, spec.md §7.39): a modification on its date and
        // its revert on that one; a part fitted or removed without a record on its date. What a
        // service record or a modification fitted and took off is its second line.
        $modifications = [];
        $reverts = [];
        $standalone = [];
        $partsLines = [];
        if ($enabled[Feature::Parts->value]) {
            $this->modifications->prime($ids);
            $this->parts->prime($ids);
            if ($query->includes(ActivityKind::Modification) || $query->includes(ActivityKind::ModificationReverted)) {
                // Each vehicle's list is read once per request (primed above).
                $all = [];
                foreach ($ids as $id) {
                    array_push($all, ...$this->modifications->listForVehicle($id));
                }
                foreach ($all as $modification) {
                    if ($query->includes(ActivityKind::Modification) && $query->covers($modification->data->doneOn)) {
                        $modifications[] = $modification;
                    }
                    $on = $modification->revertedOn;
                    if ($query->includes(ActivityKind::ModificationReverted) && $on !== null && $query->covers($on)) {
                        $reverts[] = $modification;
                    }
                }
            }
            if ($query->includes(ActivityKind::PartFitted) || $query->includes(ActivityKind::PartRemoved)) {
                $standalone = $this->standaloneFittings($ids);
            }
            $partsLines = $this->partsLines($ids, $services, $modifications);
        }

        $milestones = [];
        if ($query->includes(ActivityKind::Milestone)) {
            foreach ($query->vehicles as $vehicle) {
                foreach (self::milestones($vehicle) as [$milestone, $date, $price]) {
                    if ($query->covers($date)) {
                        $milestones[] = [$vehicle, $milestone, $date, $price];
                    }
                }
            }
        }
        $paperwork = static fn (AttachmentOwner $owner): array => array_values(array_map(
            static fn (array $m): int => $m[0]->id,
            array_filter($milestones, static fn (array $m): bool => $m[1]->filesOwner() === $owner),
        ));

        $counts = $this->attachments->countsFor($ids, array_filter([
            AttachmentOwner::Fuel->value => array_map(static fn ($e): int => $e->id, $fills),
            AttachmentOwner::Odometer->value => array_map(static fn ($r): int => $r->id, $readings),
            AttachmentOwner::Maintenance->value => array_map(static fn ($e): int => $e->id, $services),
            AttachmentOwner::Compliance->value => array_map(static fn ($d): int => $d->id, $documents),
            AttachmentOwner::Expense->value => array_map(static fn ($e): int => $e->id, $expenses),
            AttachmentOwner::Valuation->value => array_map(static fn ($v): int => $v->id, $valuations),
            AttachmentOwner::Trip->value => array_map(static fn (Trip $t): int => $t->id, $trips),
            AttachmentOwner::Incident->value => array_map(static fn (Incident $i): int => $i->id, $incidents),
            AttachmentOwner::Issue->value => array_map(static fn (Issue $i): int => $i->id, $noticed),
            AttachmentOwner::Modification->value => array_map(static fn (Modification $m): int => $m->id, $modifications),
            AttachmentOwner::Purchase->value => $paperwork(AttachmentOwner::Purchase),
            AttachmentOwner::Sale->value => $paperwork(AttachmentOwner::Sale),
        ]));

        $vehicles = [];
        $currencies = [];
        foreach ($query->vehicles as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
            $currencies[$vehicle->id] = $this->vehicles->currencyFor($user, $vehicle);
        }
        $items = [];
        foreach ($fills as $entry) {
            $data = $entry->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Fuel,
                vehicle: $vehicles[$entry->vehicleId],
                entryId: $entry->id,
                date: LocalTime::dateOf($data->filledAt, $zone),
                createdAt: $entry->createdAt,
                label: '',
                labelKey: $data->fuel->isElectric() ? 'history.kind.charge' : 'history.kind.fill_up',
                icon: $data->fuel->isElectric() ? 'ev_station' : 'local_gas_station',
                amount: $data->totalCost,
                currency: $currencies[$entry->vehicleId],
                odometerKm: $data->odometerKm,
                fuel: $data->fuel,
                grade: $data->grade,
                volume: $data->volume,
                files: $counts->of(AttachmentOwner::Fuel, $entry->id),
                createdBy: $entry->createdBy,
            );
        }
        foreach ($readings as $reading) {
            $items[] = new ActivityItem(
                kind: ActivityKind::Odometer,
                vehicle: $vehicles[$reading->vehicleId],
                entryId: $reading->id,
                date: LocalTime::dateOf($reading->recordedAt, $zone),
                createdAt: $reading->createdAt,
                label: '',
                labelKey: 'history.kind.reading',
                icon: 'speed',
                odometerKm: $reading->readingKm,
                note: $reading->note,
                files: $counts->of(AttachmentOwner::Odometer, $reading->id),
                createdBy: $reading->createdBy,
            );
        }
        foreach ($services as $entry) {
            $data = $entry->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Maintenance,
                vehicle: $vehicles[$entry->vehicleId],
                entryId: $entry->id,
                date: $data->performedOn,
                createdAt: $entry->createdAt,
                label: $data->title,
                labelKey: 'maintenance.category.' . $data->category->value,
                icon: $data->category->icon(),
                amount: $data->cost,
                currency: $currencies[$entry->vehicleId],
                odometerKm: $data->odometerKm,
                vendor: $data->vendor,
                files: $counts->of(AttachmentOwner::Maintenance, $entry->id),
                createdBy: $entry->createdBy,
                tyres: $tyresOn
                    ? array_map(static fn (TyreChange $c): TranslatableMessage => $summaries[$c->id], $linkedTo[$entry->id] ?? [])
                    : [],
                partOfKey: $partOf($entry->incidentId)?->data->type->labelKey(),
                partOfDate: $partOf($entry->incidentId)?->data->occurredOn,
                parts: $partsLines['maintenance:' . $entry->id] ?? [],
            );
        }
        foreach ($ownChanges as $change) {
            $items[] = new ActivityItem(
                kind: ActivityKind::Tyre,
                vehicle: $vehicles[$change->vehicleId],
                entryId: $change->id,
                date: $change->data->doneOn,
                createdAt: $change->createdAt,
                label: '',
                labelKey: 'tyre.kind_short.' . $change->kind->value,
                icon: $change->kind->icon(),
                currency: $currencies[$change->vehicleId],
                odometerKm: $change->data->odometerKm,
                tyres: [$summaries[$change->id]],
                createdBy: $change->createdBy,
                partOfKey: $partOf($change->incidentId)?->data->type->labelKey(),
                partOfDate: $partOf($change->incidentId)?->data->occurredOn,
            );
        }
        foreach ($documents as $document) {
            $data = $document->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Document,
                vehicle: $vehicles[$document->vehicleId],
                entryId: $document->id,
                date: self::documentDate($document, $zone),
                createdAt: $document->createdAt,
                label: $data->title ?? '',
                labelKey: 'compliance.type.' . $data->type->value,
                icon: $data->type->icon(),
                amount: $data->cost,
                currency: $currencies[$document->vehicleId],
                odometerKm: $data->odometerKm,
                expiresOn: $data->expiryOn,
                files: $counts->of(AttachmentOwner::Compliance, $document->id),
                createdBy: $document->createdBy,
            );
        }
        foreach ($expenses as $expense) {
            $data = $expense->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Expense,
                vehicle: $vehicles[$expense->vehicleId],
                entryId: $expense->id,
                date: $data->spentOn,
                createdAt: $expense->createdAt,
                label: '',
                labelKey: 'expense.category.' . $data->category->value,
                icon: $data->category->icon(),
                amount: $data->amount,
                currency: $currencies[$expense->vehicleId],
                note: $data->note,
                files: $counts->of(AttachmentOwner::Expense, $expense->id),
                createdBy: $expense->createdBy,
                partOfKey: $partOf($expense->incidentId)?->data->type->labelKey(),
                partOfDate: $partOf($expense->incidentId)?->data->occurredOn,
            );
        }
        foreach ($valuations as $valuation) {
            $data = $valuation->data;
            // A value, not a cost: the amount is its price, never in the amount column.
            $items[] = new ActivityItem(
                kind: ActivityKind::Valuation,
                vehicle: $vehicles[$valuation->vehicleId],
                entryId: $valuation->id,
                date: $data->valuedOn,
                createdAt: $valuation->createdAt,
                label: $data->source ?? '',
                labelKey: 'history.kind.valuation',
                icon: 'price_check',
                currency: $currencies[$valuation->vehicleId],
                price: $data->amount,
                files: $counts->of(AttachmentOwner::Valuation, $valuation->id),
                createdBy: $valuation->createdBy,
            );
        }
        foreach ($trips as $trip) {
            $data = $trip->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Trip,
                vehicle: $vehicles[$trip->vehicleId],
                entryId: $trip->id,
                date: $data->travelledOn,
                createdAt: $trip->createdAt,
                label: $trip->journey(),
                labelKey: $data->isBusiness ? 'history.kind.trip_business' : 'history.kind.trip_private',
                icon: 'route',
                note: $data->purpose,
                files: $counts->of(AttachmentOwner::Trip, $trip->id),
                createdBy: $trip->createdBy,
                distanceKm: $data->distanceKm,
            );
        }
        foreach ($incidents as $incident) {
            $data = $incident->data;
            // The summary everyone may see (spec.md §7.29 Access): the date, type and damage.
            $items[] = new ActivityItem(
                kind: ActivityKind::Incident,
                vehicle: $vehicles[$incident->vehicleId],
                entryId: $incident->id,
                date: $data->occurredOn,
                createdAt: $incident->createdAt,
                label: '',
                labelKey: $data->type->labelKey(),
                icon: 'car_crash',
                currency: $currencies[$incident->vehicleId],
                files: $counts->of(AttachmentOwner::Incident, $incident->id),
                createdBy: $incident->createdBy,
                damage: [
                    ...array_map(static fn (DamageArea $area): string => $area->labelKey(), $data->damageAreas),
                    ...($data->severity === null ? [] : [$data->severity->labelKey()]),
                ],
                linked: $linkedSummaries[$incident->id] ?? [],
            );
        }
        foreach ($noticed as $issue) {
            $data = $issue->data;
            // The owner's words, never a cause (spec.md §7.37).
            $items[] = new ActivityItem(
                kind: ActivityKind::IssueNoticed,
                vehicle: $vehicles[$issue->vehicleId],
                entryId: $issue->id,
                date: $data->noticedOn,
                createdAt: $issue->createdAt,
                label: $data->title,
                labelKey: 'history.kind.issue_noticed',
                icon: 'report',
                odometerKm: $data->odometerKm,
                note: $data->affectsSafety ? 'issue.affects_safety' : null,
                files: $counts->of(AttachmentOwner::Issue, $issue->id),
                createdBy: $issue->createdBy,
            );
        }
        foreach ($fixedIssues as $issue) {
            $fixedOn = $issue->fixedOn ?? $issue->data->noticedOn;
            $linked = array_map(
                static fn (array $fix): array => ['title' => $fix['title'], 'isKey' => false, 'date' => $fix['date']],
                $fixSummaries[$issue->id] ?? [],
            );
            $items[] = new ActivityItem(
                kind: ActivityKind::IssueFixed,
                vehicle: $vehicles[$issue->vehicleId],
                entryId: $issue->id,
                date: $fixedOn,
                // After the record that fixed it on the same day.
                createdAt: $issue->updatedAt,
                label: $issue->data->title,
                labelKey: 'history.kind.issue_fixed',
                icon: 'check_circle',
                createdBy: $issue->createdBy,
                linked: $linked !== []
                    ? $linked
                    : [['title' => 'issue.fixes.without_record', 'isKey' => true, 'date' => $fixedOn]],
            );
        }
        foreach ($modifications as $modification) {
            $data = $modification->data;
            $items[] = new ActivityItem(
                kind: ActivityKind::Modification,
                vehicle: $vehicles[$modification->vehicleId],
                entryId: $modification->id,
                date: $data->doneOn,
                createdAt: $modification->createdAt,
                label: $data->title,
                labelKey: $data->category->labelKey(),
                icon: 'handyman',
                amount: $data->cost,
                currency: $currencies[$modification->vehicleId],
                odometerKm: $data->odometerKm,
                vendor: $data->fittedBy,
                files: $counts->of(AttachmentOwner::Modification, $modification->id),
                createdBy: $modification->createdBy,
                parts: $partsLines['modification:' . $modification->id] ?? [],
                reverted: $modification->isReverted(),
            );
        }
        foreach ($reverts as $modification) {
            $items[] = new ActivityItem(
                kind: ActivityKind::ModificationReverted,
                vehicle: $vehicles[$modification->vehicleId],
                entryId: $modification->id,
                date: $modification->revertedOn ?? $modification->data->doneOn,
                createdAt: $modification->updatedAt,
                label: $modification->data->title,
                labelKey: 'history.kind.modification_reverted',
                icon: 'restart_alt',
                odometerKm: $modification->revertedOdometerKm,
                note: $modification->revertedNote,
                createdBy: $modification->createdBy,
            );
        }
        $partsById = [];
        if ($standalone !== []) {
            foreach ($ids as $id) {
                foreach ($this->parts->listForVehicle($id) as $part) {
                    $partsById[$part->id] = $part;
                }
            }
        }
        foreach ($standalone as $fitting) {
            $part = $partsById[$fitting->partId] ?? null;
            if ($part === null) {
                continue;
            }
            $fittedHere = !$fitting->fittedByRecord() && $query->covers($fitting->fittedOn);
            if ($query->includes(ActivityKind::PartFitted) && $fittedHere) {
                $items[] = new ActivityItem(
                    kind: ActivityKind::PartFitted,
                    vehicle: $vehicles[$part->vehicleId],
                    entryId: $part->id,
                    date: $fitting->fittedOn,
                    createdAt: $fitting->createdAt,
                    label: $part->label(),
                    labelKey: 'history.kind.part_fitted',
                    icon: 'inventory_2',
                    odometerKm: $fitting->fittedOdometerKm,
                    createdBy: $part->createdBy,
                );
            }
            $removedOn = $fitting->removedOn;
            if (
                $query->includes(ActivityKind::PartRemoved) && $removedOn !== null
                && !$fitting->removedByRecord() && $query->covers($removedOn)
            ) {
                $items[] = new ActivityItem(
                    kind: ActivityKind::PartRemoved,
                    vehicle: $vehicles[$part->vehicleId],
                    entryId: $part->id,
                    date: $removedOn,
                    createdAt: $fitting->updatedAt,
                    label: $part->label(),
                    labelKey: 'history.kind.part_removed',
                    icon: 'undo',
                    odometerKm: $fitting->removedOdometerKm,
                    note: $fitting->outcome?->labelKey(),
                    createdBy: $part->createdBy,
                );
            }
        }
        foreach ($milestones as [$vehicle, $milestone, $date, $price]) {
            $owner = $milestone->filesOwner();
            $totalLoss = $milestone === Milestone::WrittenOff ? $partOf($vehicle->disposalIncidentId) : null;
            $items[] = new ActivityItem(
                kind: ActivityKind::Milestone,
                vehicle: $vehicle,
                entryId: $vehicle->id,
                date: $date,
                createdAt: $vehicle->createdAt,
                label: '',
                labelKey: 'history.milestone.' . $milestone->value,
                icon: $milestone->icon(),
                currency: $currencies[$vehicle->id],
                milestone: $milestone,
                price: $price,
                files: $owner === null ? 0 : $counts->of($owner, $vehicle->id),
                // *Written off* links its incident, as a record names the incident it is part of.
                partOfKey: $totalLoss?->data->type->labelKey(),
                partOfDate: $totalLoss?->data->occurredOn,
            );
        }

        foreach ($tests as $test) {
            $items[] = new ActivityItem(
                kind: ActivityKind::MotTest,
                vehicle: $vehicles[$test->vehicleId],
                entryId: $test->id,
                date: LocalTime::dateOf($test->completedAt, $zone),
                createdAt: $test->completedAt,
                label: '',
                labelKey: $test->passed() ? 'history.kind.mot_passed' : 'history.kind.mot_failed',
                icon: 'fact_check',
                odometerKm: $test->odometerKm,
                expiresOn: $test->expiryOn,
                defects: $test->defectCount(),
            );
        }

        usort($items, ActivityItem::compare(...));

        return $query->limit === null ? $items : array_slice($items, 0, $query->limit);
    }

    /**
     * Split tyre changes into those listed on their own and those a listed
     * service record carries. A change is carried only while maintenance is
     * on and its record is in $services (a linked change always has its
     * record's date, so both fall in the same range).
     *
     * @param list<TyreChange> $changes
     * @param list<MaintenanceEntry> $services
     * @return array{0: list<TyreChange>, 1: array<int, list<TyreChange>>}
     */
    private function splitLinked(array $changes, array $services, bool $maintenanceOn): array
    {
        $records = [];
        foreach ($services as $entry) {
            $records[$entry->id] = true;
        }
        $own = [];
        $linkedTo = [];
        foreach ($changes as $change) {
            $record = $change->data->maintenanceEntryId;
            if ($maintenanceOn && $record !== null && isset($records[$record])) {
                $linkedTo[$record][] = $change;
            } else {
                $own[] = $change;
            }
        }

        return [$own, $linkedTo];
    }

    /**
     * Each change's summary line, from one read of the tyres and sets of the page's vehicles.
     *
     * @param list<int> $vehicleIds
     * @param list<TyreChange> $changes
     * @return array<int, TranslatableMessage> by change id
     */
    private function tyreSummaries(array $vehicleIds, array $changes, DepthUnit $unit): array
    {
        if ($changes === []) {
            return [];
        }
        $tyres = [];
        foreach ($this->tyres->listTyresOf($vehicleIds) as $tyre) {
            $tyres[$tyre->id] = $tyre;
        }
        $sets = TyreService::setsById($this->tyres->listSetsOf($vehicleIds));
        $summaries = [];
        foreach ($changes as $change) {
            $summaries[$change->id] = TyreSummary::line($change, $tyres, $sets, $unit);
        }

        return $summaries;
    }

    /**
     * The first and newest years with anything of the query's kinds.
     *
     * @param list<ComplianceDocument> $documents
     * @param list<MotTest> $tests
     * @return array{0: ?int, 1: ?int}
     */
    private function span(ActivityQuery $query, array $documents, array $tests, DateTimeZone $zone): array
    {
        $years = $this->placedYears($query, $documents, $tests, $zone);
        foreach ($this->datedSources($query) as $source) {
            foreach ($this->dates->span($source, $query->vehicleIds()) as $date) {
                if ($date !== null) {
                    $years[] = self::yearOf($source, $date, $zone);
                }
            }
        }

        return $years === [] ? [null, null] : [min($years), max($years)];
    }

    /**
     * The nearest later year with anything, or null.
     *
     * @param list<ComplianceDocument> $documents
     * @param list<MotTest> $tests
     */
    private function newer(ActivityQuery $query, array $documents, array $tests, DateTimeZone $zone, int $year): ?int
    {
        $placed = $this->placedYears($query, $documents, $tests, $zone);
        $years = array_filter($placed, static fn (int $y): bool => $y > $year);
        foreach ($this->datedSources($query) as $source) {
            $date = $this->dates->earliestFrom($source, $query->vehicleIds(), self::bound($source, $year + 1, $zone));
            if ($date !== null) {
                $years[] = self::yearOf($source, $date, $zone);
            }
        }

        return $years === [] ? null : min($years);
    }

    /**
     * The nearest earlier year with anything, or null.
     *
     * @param list<ComplianceDocument> $documents
     * @param list<MotTest> $tests
     */
    private function older(ActivityQuery $query, array $documents, array $tests, DateTimeZone $zone, int $year): ?int
    {
        $placed = $this->placedYears($query, $documents, $tests, $zone);
        $years = array_filter($placed, static fn (int $y): bool => $y < $year);
        foreach ($this->datedSources($query) as $source) {
            $date = $this->dates->latestBefore($source, $query->vehicleIds(), self::bound($source, $year, $zone));
            if ($date !== null) {
                $years[] = self::yearOf($source, $date, $zone);
            }
        }

        return $years === [] ? null : max($years);
    }

    /**
     * Years of the lines placed in PHP: documents, MOT tests and milestones.
     *
     * @param list<ComplianceDocument> $documents
     * @param list<MotTest> $tests
     * @return list<int>
     */
    private function placedYears(ActivityQuery $query, array $documents, array $tests, DateTimeZone $zone): array
    {
        $years = array_map(
            static fn (ComplianceDocument $d): int => (int) self::documentDate($d, $zone)->format('Y'),
            $documents,
        );
        foreach ($tests as $test) {
            $years[] = (int) LocalTime::fromUtc($test->completedAt, $zone)->format('Y');
        }
        // Parts and modifications (Phase 50.1), from the lists read once per request.
        $partsOn = $this->features->isEnabled(Feature::Parts);
        if ($partsOn) {
            $this->modifications->prime($query->vehicleIds());
            $this->parts->prime($query->vehicleIds());
        }
        if ($partsOn && ($query->includes(ActivityKind::Modification) || $query->includes(ActivityKind::ModificationReverted))) {
            foreach ($query->vehicleIds() as $vehicleId) {
                foreach ($this->modifications->listForVehicle($vehicleId) as $modification) {
                    if ($query->includes(ActivityKind::Modification)) {
                        $years[] = (int) $modification->data->doneOn->format('Y');
                    }
                    if ($query->includes(ActivityKind::ModificationReverted) && $modification->revertedOn !== null) {
                        $years[] = (int) $modification->revertedOn->format('Y');
                    }
                }
            }
        }
        if ($partsOn && ($query->includes(ActivityKind::PartFitted) || $query->includes(ActivityKind::PartRemoved))) {
            foreach ($this->standaloneFittings($query->vehicleIds()) as $fitting) {
                if (!$fitting->fittedByRecord()) {
                    $years[] = (int) $fitting->fittedOn->format('Y');
                }
                if ($fitting->removedOn !== null && !$fitting->removedByRecord()) {
                    $years[] = (int) $fitting->removedOn->format('Y');
                }
            }
        }
        if ($query->includes(ActivityKind::Milestone)) {
            foreach ($query->vehicles as $vehicle) {
                foreach (self::milestones($vehicle) as [, $date]) {
                    $years[] = (int) $date->format('Y');
                }
            }
        }

        return $years;
    }

    /**
     * What each service record and modification fitted and took off, as its
     * second line, keyed "maintenance:{id}" / "modification:{id}" (Phase 50.1).
     *
     * @param list<int> $vehicleIds
     * @param list<MaintenanceEntry> $services
     * @param list<Modification> $modifications
     * @return array<string, list<TranslatableMessage>>
     */
    private function partsLines(array $vehicleIds, array $services, array $modifications): array
    {
        $wanted = [
            'maintenance' => array_fill_keys(array_map(static fn (MaintenanceEntry $e): int => $e->id, $services), true),
            'modification' => array_fill_keys(array_map(static fn (Modification $m): int => $m->id, $modifications), true),
        ];
        $fitted = [];
        $removed = [];
        foreach ($vehicleIds as $vehicleId) {
            $labels = [];
            foreach ($this->parts->listForVehicle($vehicleId) as $part) {
                $labels[$part->id] = $part->label();
            }
            foreach ($this->parts->fittingsForVehicle($vehicleId) as $partId => $fittings) {
                $label = $labels[$partId] ?? null;
                if ($label === null) {
                    continue;
                }
                foreach ($fittings as $fitting) {
                    foreach (
                        [
                            ['maintenance', $fitting->fittedMaintenanceId, $fitting->removedMaintenanceId],
                            ['modification', $fitting->fittedModificationId, $fitting->removedModificationId],
                        ] as [$kind, $on, $off]
                    ) {
                        if ($on !== null && isset($wanted[$kind][$on]) && !$fitting->fittedByRevert) {
                            $fitted[$kind . ':' . $on][] = $label;
                        }
                        if ($off !== null && isset($wanted[$kind][$off]) && !$fitting->removedByRevert) {
                            $removed[$kind . ':' . $off][] = $label;
                        }
                    }
                }
            }
        }
        $lines = [];
        foreach (array_unique([...array_keys($fitted), ...array_keys($removed)]) as $key) {
            if (isset($fitted[$key])) {
                $lines[$key][] = new TranslatableMessage('history.parts_fitted', ['parts' => implode(', ', $fitted[$key])]);
            }
            if (isset($removed[$key])) {
                $lines[$key][] = new TranslatableMessage('history.parts_removed', ['parts' => implode(', ', $removed[$key])]);
            }
        }

        return $lines;
    }

    /**
     * The fittings and removals made without a record (Phase 50.1), from
     * each vehicle's fittings, read once per request.
     *
     * @param list<int> $vehicleIds
     * @return list<PartFitting>
     */
    private function standaloneFittings(array $vehicleIds): array
    {
        $standalone = [];
        foreach ($vehicleIds as $vehicleId) {
            foreach ($this->parts->fittingsForVehicle($vehicleId) as $fittings) {
                foreach ($fittings as $fitting) {
                    if (!$fitting->fittedByRecord() || ($fitting->removedOn !== null && !$fitting->removedByRecord())) {
                        $standalone[] = $fitting;
                    }
                }
            }
        }

        return $standalone;
    }

    /**
     * @return list<DatedSource>
     */
    private function datedSources(ActivityQuery $query): array
    {
        $sources = [];
        foreach ($query->kinds as $kind) {
            if ($kind->datedSource() !== null) {
                $sources[] = $kind->datedSource();
            }
        }

        return $sources;
    }

    /**
     * The vehicle's milestones that have a date.
     *
     * @return list<array{0: Milestone, 1: DateTimeImmutable, 2: ?string}>
     */
    private static function milestones(Vehicle $vehicle): array
    {
        $data = $vehicle->data;

        return array_values(array_filter([
            $data->firstRegisteredOn === null ? null : [Milestone::FirstRegistered, $data->firstRegisteredOn, null],
            $data->purchaseDate === null ? null : [Milestone::Bought, $data->purchaseDate, $data->purchasePrice],
            $data->saleDate === null
                ? null
                : [self::saleMilestone($vehicle), $data->saleDate, $data->salePrice],
        ]));
    }

    /** *Sold*, named for how the vehicle left: written off or returned. */
    private static function saleMilestone(Vehicle $vehicle): Milestone
    {
        return match ($vehicle->disposal) {
            Disposal::WrittenOff => Milestone::WrittenOff,
            Disposal::ReturnedLender => Milestone::ReturnedLender,
            Disposal::ReturnedLessor => Milestone::ReturnedLessor,
            default => Milestone::Sold,
        };
    }

    /**
     * As the cost ledger dates it: its start, else the day it was added.
     */
    private static function documentDate(ComplianceDocument $document, DateTimeZone $zone): DateTimeImmutable
    {
        return $document->data->startOn ?? LocalTime::dateOf($document->createdAt, $zone);
    }

    /**
     * The UTC instant a calendar day starts in the owner's zone.
     */
    private static function startOf(DateTimeImmutable $day, DateTimeZone $zone): DateTimeImmutable
    {
        return LocalTime::toUtc($day->format('Y-m-d') . 'T00:00', $zone) ?? $day;
    }

    /**
     * Where a year starts, in the source's terms (an instant or a date).
     */
    private static function bound(DatedSource $source, int $year, DateTimeZone $zone): DateTimeImmutable
    {
        $day = ActivityQuery::newYear($year);

        return $source->isInstant() ? self::startOf($day, $zone) : $day;
    }

    private static function yearOf(DatedSource $source, DateTimeImmutable $date, DateTimeZone $zone): int
    {
        return (int) ($source->isInstant() ? LocalTime::fromUtc($date, $zone) : $date)->format('Y');
    }
}
