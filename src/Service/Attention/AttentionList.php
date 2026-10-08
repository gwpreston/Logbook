<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Repository\IncidentRepository;
use Logbook\Repository\IssueRepository;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Service\Incident\IncidentAccess;
use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttentionHiddenRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Finance\MileageAllowance;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastSource;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Fuel\SegmentCheck;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\GeneratedReminder;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\MileageSplit;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * *Needs attention* (spec.md §7.24): what is wrong with each vehicle right
 * now, for one user, gathered from the services that own each fact. It
 * judges nothing new apart from the stale-mileage rule and the trend and
 * cost checks (TrendChecks, Phase 25): overdue work is *Coming up*'s
 * overdue group, readings are the Mileage tab's plausibility flags,
 * economy is the Fuel tab's check, trips are the Mileage tab's split, and
 * a stale valuation is §7.1's rule. A switched-off module's items leave
 * the list; archived vehicles raise nothing.
 *
 * Each source is loaded once per vehicle, never once per reading or
 * fill-up. Safe from the command line (the digest): it reads no request.
 */
final readonly class AttentionList
{
    /** A claim waiting longer than this many days is raised (spec.md §7.24 item 10). */
    public const int STALLED_CLAIM_DAYS = 30;

    public function __construct(
        private FeatureToggles $features,
        private ReminderSync $sync,
        private ReminderRepository $reminders,
        private ComingUp $comingUp,
        private OdometerService $odometer,
        private OdometerReadingRepository $readings,
        private FuelService $fuel,
        private ScheduleService $schedules,
        private TyreService $tyres,
        private ValuationService $valuations,
        private MileageSplit $split,
        private ClaimReportService $claims,
        private AttentionSettingsStore $settings,
        private AttentionHiddenRepository $hidden,
        private TrendChecks $trends,
        private VehicleAccess $access,
        private UserDirectory $directory,
        private ClockInterface $clock,
        private IncidentRepository $incidents,
        private IncidentAccess $incidentAccess,
        private FinanceService $finance,
        private IssueRepository $issues,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles vehicles the user can view
     * @param bool $sync false when the reminders were already brought up to date in this request
     * @param bool $withHidden keep the items the user has hidden (to check a *Hide*)
     * @param Forecast|null $forecast *Coming up* for this user and these vehicles, when the page has it already
     */
    public function forVehicles(
        User $user,
        array $vehicles,
        bool $sync = true,
        bool $withHidden = false,
        ?Forecast $forecast = null,
    ): AttentionReport {
        $vehicles = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => !$v->isArchived()));
        if ($vehicles === []) {
            return new AttentionReport();
        }
        $enabled = $this->features->all();
        $withReminders = $enabled[Feature::Reminders->value];
        $ids = array_map(static fn (Vehicle $v): int => $v->id, $vehicles);

        // Dismissals hold only for a reminder's current occurrence: bring
        // them up to date before reading them (spec.md §7.6 Sync).
        if ($withReminders && $sync) {
            $this->sync->sync($user);
        }
        $stored = [];
        if ($withReminders) {
            foreach ($this->reminders->listGeneratedForVehicles($ids) as $reminder) {
                $stored[GeneratedReminder::keyOf($reminder->vehicleId, $reminder->source, $reminder->sourceId)] = $reminder;
            }
        }

        $overdue = [];
        foreach (($forecast ?? $this->comingUp->forecast($user, $vehicles))->overdue as $item) {
            $overdue[$item->vehicle->id][] = $item;
        }
        $hidden = $withHidden ? [] : $this->hidden->fingerprints($user->id, $ids);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $book = new PriceBook();
        // Phase 40.1: every vehicle's open and watching issues in one query.
        $issues = [];
        if ($enabled[Feature::Issues->value]) {
            foreach ($this->issues->listForVehicles($ids, [IssueStatus::Open, IssueStatus::Watching]) as $issue) {
                $issues[$issue->vehicleId][] = $issue;
            }
        }

        $items = [];
        foreach ($vehicles as $vehicle) {
            $canLog = $this->access->can($user, VehicleAbility::Log, $vehicle);
            foreach ($overdue[$vehicle->id] ?? [] as $forecast) {
                $item = $this->overdue($vehicle, $forecast, $stored, $withReminders, $canLog, $today);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if ($canLog) {
                array_push($items, ...$this->checks($user, $vehicle, $enabled, $today, $overdue[$vehicle->id] ?? [], $book));
            }
            if ($enabled[Feature::Finance->value]) {
                array_push($items, ...$this->financeItems($user, $vehicle));
            }
            if (isset($issues[$vehicle->id])) {
                array_push($items, ...$this->issueItems($user, $vehicle, $issues[$vehicle->id], $stored, $canLog));
            }
        }

        $items = array_values(array_filter(
            $items,
            static fn (AttentionItem $i): bool => $i->fingerprint === null
                || ($hidden[AttentionHiddenRepository::key($i->kind, $i->subjectId)] ?? null) !== $i->fingerprint,
        ));
        usort($items, AttentionItem::compare(...));

        return new AttentionReport($items);
    }

    /**
     * One overdue *Coming up* item, unless its current reminder is
     * dismissed or done (a tyre reminder covers all the vehicle's tyres).
     *
     * @param array<string, Reminder> $stored generated reminders by key
     */
    private function overdue(
        Vehicle $vehicle,
        ForecastItem $forecast,
        array $stored,
        bool $withReminders,
        bool $canLog,
        DateTimeImmutable $today,
    ): ?AttentionItem {
        $reminderId = null;
        if ($withReminders) {
            if ($forecast->source === ForecastSource::Reminder) {
                // Coming up lists open manual reminders only.
                $reminderId = $forecast->sourceId;
            } else {
                $reminder = $stored[GeneratedReminder::keyOf(
                    $vehicle->id,
                    self::reminderSource($forecast->source),
                    $forecast->sourceId,
                )] ?? null;
                if ($reminder !== null && $reminder->status->isClosed()) {
                    return null;
                }
                $reminderId = $reminder?->id;
            }
        }
        $manual = $forecast->source === ForecastSource::Reminder;

        return new AttentionItem(
            kind: AttentionKind::Overdue,
            vehicle: $vehicle,
            subjectId: $forecast->sourceId,
            icon: $forecast->icon,
            forecast: $forecast,
            days: $forecast->dueOn !== null && $forecast->dueOn < $today
                ? LocalTime::daysBetween($forecast->dueOn, $today)
                : null,
            reminderId: $reminderId,
            canAct: $manual ? $canLog && $reminderId !== null : $canLog,
            canDismiss: $canLog && $reminderId !== null,
        );
    }

    private static function reminderSource(ForecastSource $source): ReminderSource
    {
        return match ($source) {
            ForecastSource::Schedule => ReminderSource::Schedule,
            ForecastSource::Document => ReminderSource::Compliance,
            ForecastSource::Tyres => ReminderSource::Tyre,
            ForecastSource::FirstInspection => ReminderSource::FirstInspection,
            ForecastSource::Reminder => ReminderSource::Manual,
            // Finance items are never overdue in *Coming up* (missed payments are their own check).
            ForecastSource::Finance => ReminderSource::Finance,
        };
    }

    /**
     * The data checks a user who can log on this vehicle may fix. Judged
     * by the owner's thresholds and today, whoever looks (Phase 19). The
     * fuel history is loaded once and shared by the economy flags and the
     * trend checks.
     *
     * @param array<string, bool> $enabled
     * @param list<ForecastItem> $overdue the vehicle's overdue *Coming up* items
     * @return list<AttentionItem>
     */
    private function checks(
        User $user,
        Vehicle $vehicle,
        array $enabled,
        DateTimeImmutable $today,
        array $overdue,
        PriceBook $book,
    ): array {
        $manage = $this->access->can($user, VehicleAbility::Manage, $vehicle);
        $owner = $vehicle->userId === $user->id ? $user : $this->directory->find($vehicle->userId) ?? $user;
        $zone = $owner->preferences->timeZone();
        $ownerToday = LocalTime::today($this->clock, $zone);
        $thresholds = $this->settings->thresholds($owner->id);
        $history = $this->odometer->history($vehicle);

        $items = $this->readingChecks($user, $vehicle, $history, $manage);
        $fuel = $enabled[Feature::Fuel->value] ? $this->fuel->history($vehicle) : null;
        if ($fuel !== null) {
            $flagged = array_filter(
                $this->fuel->checks($fuel)->flagged(),
                static fn (SegmentCheck $c): bool => $manage || EntryAccess::isOwn($user, $c->entry()->createdBy),
            );
            if ($flagged !== []) {
                $items[] = new AttentionItem(
                    kind: AttentionKind::Economy,
                    vehicle: $vehicle,
                    subjectId: $vehicle->id,
                    icon: 'local_gas_station',
                    count: count($flagged),
                    canAct: true,
                );
            }
        }

        $latest = $history->latest();
        $needsReadings = $this->needsReadings($vehicle, $owner, $enabled);
        if (StaleMileage::isStale($latest, $needsReadings, $ownerToday, $zone, $thresholds->mileageDays)) {
            $items[] = new AttentionItem(
                kind: AttentionKind::MileageStale,
                vehicle: $vehicle,
                subjectId: $vehicle->id,
                icon: 'speed',
                latest: $latest,
                fingerprint: Fingerprint::mileage($latest),
                canAct: true,
                canHide: true,
            );
        }

        if ($enabled[Feature::Trips->value]) {
            $year = $this->claims->taxYearOf($user, $today);
            if ($this->split->forVehicle($user, $vehicle, $year->start, min($today, $year->lastDay()))->exceeds) {
                $items[] = new AttentionItem(
                    kind: AttentionKind::TripsExceed,
                    vehicle: $vehicle,
                    subjectId: $vehicle->id,
                    icon: 'route',
                    canAct: true,
                );
            }
        }

        if ($manage) {
            $valuation = $this->staleValuation($vehicle, $ownerToday, $thresholds->valuationMonths);
            if ($valuation !== null) {
                $items[] = $valuation;
            }
        }

        if ($enabled[Feature::Incidents->value]) {
            array_push($items, ...$this->stalledClaims($user, $vehicle, $ownerToday));
        }

        $serviceOverdue = $enabled[Feature::Maintenance->value] && array_filter(
            $overdue,
            static fn (ForecastItem $i): bool => $i->source === ForecastSource::Schedule,
        ) !== [];
        array_push($items, ...$this->trends->items(
            $user,
            $vehicle,
            $owner,
            $thresholds,
            $manage,
            $this->access->can($user, VehicleAbility::ViewCosts, $vehicle),
            $enabled,
            $fuel,
            $serviceOverdue,
            $this->clock->now(),
            $book,
        ));

        return $items;
    }

    /**
     * One item per flagged reading the user could fix: with Manage any, with
     * Log one they added (a derived reading's author is its entry's).
     *
     * @return list<AttentionItem>
     */
    private function readingChecks(User $user, Vehicle $vehicle, OdometerHistory $history, bool $manage): array
    {
        if ($history->warnings === []) {
            return [];
        }
        $authors = $manage ? [] : $this->readings->authorsForVehicle($vehicle->id);
        $readings = $history->readings;
        $items = [];
        foreach ($readings as $i => $reading) {
            $warning = $history->warnings[$reading->id] ?? null;
            if ($warning === null || !($manage || EntryAccess::isOwn($user, $authors[$reading->id] ?? null))) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: AttentionKind::Reading,
                vehicle: $vehicle,
                subjectId: $reading->id,
                icon: 'warning',
                reading: $reading,
                warning: $warning,
                fingerprint: Fingerprint::reading($readings[$i - 1] ?? null, $reading, $readings[$i + 1] ?? null),
                canAct: true,
                canHide: true,
            );
        }

        return $items;
    }

    /**
     * Whether something on the vehicle is projected by distance: a
     * distance-based schedule, or a fitted tyre with a wear estimate.
     *
     * @param array<string, bool> $enabled
     */
    private function needsReadings(Vehicle $vehicle, User $owner, array $enabled): bool
    {
        if ($enabled[Feature::Maintenance->value]) {
            foreach ($this->schedules->list($vehicle) as $schedule) {
                if ($schedule->data->intervalKm !== null) {
                    return true;
                }
            }
        }
        if ($enabled[Feature::Tyres->value]) {
            foreach ($this->tyres->verdict($vehicle, $owner)->standings as $standing) {
                if ($standing->view->tyre->isFitted() && $standing->view->wear->isKnown()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Claims told to the insurer, or open, with no news for more than
     * STALLED_CLAIM_DAYS (spec.md §7.24 item 10), as the user may see them:
     * the title names the claim, so only with its details.
     *
     * @return list<AttentionItem>
     */
    private function stalledClaims(User $user, Vehicle $vehicle, DateTimeImmutable $today): array
    {
        $items = [];
        foreach ($this->incidents->listForVehicle($vehicle->id) as $incident) {
            if (
                !$incident->data->claim->status->isAwaitingNews()
                || !$this->incidentAccess->seesDetails($user, $vehicle, $incident)
            ) {
                continue;
            }
            $days = LocalTime::daysBetween($incident->lastClaimNews(), $today);
            if ($days <= self::STALLED_CLAIM_DAYS) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: AttentionKind::StalledClaim,
                vehicle: $vehicle,
                subjectId: $incident->id,
                icon: 'car_crash',
                days: $days,
                incident: $this->incidentAccess->view($user, $vehicle, $incident),
                fingerprint: Fingerprint::claim($incident),
                canAct: true,
                canHide: true,
            );
        }

        return $items;
    }

    /**
     * *Open issue* for each open issue, and *Look again* for each watching
     * one whose look-again date (the owner's today) or mileage (the latest
     * reading) is reached, unless its look-again reminder was dismissed or
     * done (spec.md §7.37). Shown to everyone who can view; the actions
     * need `Log`. Nothing here judges what a fault is.
     *
     * @param list<Issue> $issues the vehicle's open and watching issues
     * @param array<string, Reminder> $stored generated reminders by key
     * @return list<AttentionItem>
     */
    private function issueItems(User $user, Vehicle $vehicle, array $issues, array $stored, bool $canLog): array
    {
        $owner = $vehicle->userId === $user->id ? $user : $this->directory->find($vehicle->userId) ?? $user;
        $today = LocalTime::today($this->clock, $owner->preferences->timeZone());
        $since = $this->issues->watchingSince(array_map(static fn (Issue $i): int => $i->id, $issues));
        $latest = null;
        $items = [];
        foreach ($issues as $issue) {
            $data = $issue->data;
            if ($issue->status() === IssueStatus::Open) {
                $items[] = new AttentionItem(
                    kind: AttentionKind::IssueOpen,
                    vehicle: $vehicle,
                    subjectId: $issue->id,
                    icon: 'report',
                    days: LocalTime::daysBetween($data->noticedOn, $today),
                    issue: $issue,
                    canAct: $canLog,
                );
                continue;
            }
            if ($data->lookAgainKm !== null) {
                $latest ??= $this->odometer->history($vehicle)->latest()->readingKm ?? '';
            }
            $reached = ($data->lookAgainOn !== null && $data->lookAgainOn <= $today)
                || ($data->lookAgainKm !== null && $latest !== '' && Decimal::compare($latest, $data->lookAgainKm) >= 0);
            $reminder = $stored[GeneratedReminder::keyOf($vehicle->id, ReminderSource::Issue, $issue->id)] ?? null;
            if (!$reached || ($reminder !== null && $reminder->status->isClosed())) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: AttentionKind::IssueLookAgain,
                vehicle: $vehicle,
                subjectId: $issue->id,
                icon: 'visibility',
                issue: $issue,
                since: $since[$issue->id] ?? $data->noticedOn,
                canAct: $canLog,
            );
        }

        return $items;
    }

    /**
     * The active agreement's missed payments (*Now*) and the mileage heading
     * more than 2% over the allowance (item 11), for those who may see the
     * vehicle's finance (spec.md §7.32 *Needs attention*).
     *
     * @return list<AttentionItem>
     */
    private function financeItems(User $user, Vehicle $vehicle): array
    {
        $view = $this->finance->activeView($user, $vehicle);
        if ($view === null) {
            return [];
        }
        $agreement = $view->agreement;
        $items = [];
        foreach ($view->figures->schedule->missed() as $payment) {
            $items[] = new AttentionItem(
                kind: AttentionKind::FinanceMissed,
                vehicle: $vehicle,
                subjectId: $agreement->id,
                icon: 'account_balance',
                finance: new FinanceFinding($agreement, $view->currency, dueOn: $payment->dueOn),
                canAct: true,
            );
        }
        $mileage = $view->mileage;
        $percent = $mileage?->excessPercent();
        if ($mileage !== null && $percent !== null && $percent > MileageAllowance::ATTENTION_PERCENT) {
            $items[] = new AttentionItem(
                kind: AttentionKind::FinanceMileage,
                vehicle: $vehicle,
                subjectId: $agreement->id,
                icon: 'speed',
                finance: new FinanceFinding($agreement, $view->currency, mileage: $mileage),
                fingerprint: Fingerprint::financeMileage($agreement->id, (int) $mileage->excessRounded()),
                canAct: true,
                canHide: true,
            );
        }

        return $items;
    }

    /**
     * §7.1's stale-value rule on the latest valuation of a vehicle that is
     * not sold.
     */
    private function staleValuation(Vehicle $vehicle, DateTimeImmutable $today, int $months): ?AttentionItem
    {
        if ($vehicle->data->saleDate !== null) {
            return null;
        }
        $latest = null;
        foreach ($this->valuations->forVehicle($vehicle) as $valuation) {
            if ($latest === null || self::later($valuation, $latest)) {
                $latest = $valuation;
            }
        }
        $age = $latest === null ? null : Depreciation::staleMonths($latest->data->valuedOn, $today, $months);
        if ($latest === null || $age === null) {
            return null;
        }

        return new AttentionItem(
            kind: AttentionKind::ValuationStale,
            vehicle: $vehicle,
            subjectId: $vehicle->id,
            icon: 'sell',
            valuedOn: $latest->data->valuedOn,
            months: $age,
            fingerprint: Fingerprint::valuation($latest),
            canAct: true,
            canHide: true,
        );
    }

    /**
     * The latest valuation: by date, then the one added last (as the value
     * series orders a day's valuations).
     */
    private static function later(VehicleValuation $a, VehicleValuation $b): bool
    {
        return $a->data->valuedOn > $b->data->valuedOn
            || ($a->data->valuedOn == $b->data->valuedOn && $a->id > $b->id);
    }
}
