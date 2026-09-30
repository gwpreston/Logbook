<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\CostLedger;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Service\Maintenance\ScheduleCalculator;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Tyre\TyreReminderTitle;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreStanding;
use Logbook\Service\Tyre\TyreVerdict;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Psr\Clock\ClockInterface;

/**
 * *Coming up* (spec.md §7.18): loads each active vehicle's sources and hands
 * them to ForecastCalculator. Reads the sources the reminders are built
 * from, never the generated reminders, and writes nothing: it does not sync
 * reminders, so reading it changes nothing about them or their
 * notifications. A switched-off module's items are left out.
 */
final readonly class ComingUp
{
    public function __construct(
        private VehicleService $vehicles,
        private ScheduleService $schedules,
        private MaintenanceEntryRepository $entries,
        private ComplianceService $compliance,
        private OdometerService $odometer,
        private TyreService $tyres,
        private TyreReminderTitle $tyreTitles,
        private ReminderRepository $reminders,
        private ReminderSettingsStore $reminderSettings,
        private FeatureToggles $features,
        private CostLedger $ledger,
        private ClockInterface $clock,
        private VehicleAccess $access,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles the owner's vehicles; archived ones raise nothing
     */
    public function forecast(User $user, array $vehicles): Forecast
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $vehicles = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => !$v->isArchived()));
        $enabled = $this->features->all();
        $lead = $this->reminderSettings->reminderPreferences($user->id);

        $manual = [];
        if ($enabled[Feature::Reminders->value]) {
            $ids = array_map(static fn (Vehicle $v): int => $v->id, $vehicles);
            foreach ($this->reminders->listOpenManualForVehicles($ids) as $reminder) {
                $manual[$reminder->vehicleId][] = $reminder;
            }
        }
        // Amounts only for the vehicles whose costs the user may see (spec.md §5 Costs).
        $costly = array_values(array_filter(
            $vehicles,
            fn (Vehicle $v): bool => $this->access->can($user, VehicleAbility::ViewCosts, $v),
        ));
        $ledger = [];
        if ($enabled[Feature::Fuel->value] && $costly !== []) {
            foreach ($this->ledger->items($user, $costly) as $item) {
                $ledger[$item->vehicle->id][] = $item;
            }
        }

        $sources = array_map(fn (Vehicle $vehicle): VehicleSources => $this->sources(
            $user,
            $vehicle,
            $today,
            $lead,
            $enabled,
            $manual[$vehicle->id] ?? [],
            $ledger[$vehicle->id] ?? [],
            in_array($vehicle, $costly, true),
        ), $vehicles);

        return ForecastCalculator::forecast($sources, $today);
    }

    /**
     * @param array<string, bool> $enabled
     * @param list<Reminder> $manual
     * @param list<CostItem> $ledger
     * @param bool $costs false: no amounts (the user may not see this vehicle's costs)
     */
    private function sources(
        User $user,
        Vehicle $vehicle,
        DateTimeImmutable $today,
        ReminderPreferences $lead,
        array $enabled,
        array $manual,
        array $ledger,
        bool $costs,
    ): VehicleSources {
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $history = $this->odometer->history($vehicle);
        $zone = $user->preferences->timeZone();

        $schedules = $enabled[Feature::Maintenance->value]
            ? array_map(fn (ScheduleState $state): ScheduleDue => new ScheduleDue(
                $state,
                $costs ? ForecastCalculator::cost(
                    ScheduleCalculator::latest($this->entries->listForSchedule($vehicle->id, $state->schedule->id))?->data->cost,
                    $currency,
                ) : null,
            ), $this->schedules->states($vehicle, $today, $history, $lead->scheduleDays, $lead->scheduleKm))
            : [];

        $documents = $enabled[Feature::Compliance->value]
            ? array_values(array_map(
                static fn (DocumentState $s) => $s->document,
                array_filter(
                    $this->compliance->states($vehicle, $today, $lead->documentDays),
                    static fn (DocumentState $s): bool => $s->status->isCurrent(),
                ),
            ))
            : [];

        return new VehicleSources(
            vehicle: $vehicle,
            currency: $currency,
            schedules: $schedules,
            documents: $documents,
            tyres: $enabled[Feature::Tyres->value] ? $this->tyreDues($user, $vehicle, $currency, $costs) : [],
            reminders: $manual,
            currentKm: $history->latest()?->readingKm,
            kmPerDay: $history->averageKmPerDay(),
            fuel: $enabled[Feature::Fuel->value] && $costs
                ? FuelRate::of($ledger, $history->readings, ReportPeriod::preset(ReportRange::TwelveMonths, $today), $zone)
                : null,
            costs: $costs,
        );
    }

    /**
     * Every judged tyre, grouped as the tyre reminder groups the most urgent
     * ones (TyreJudgement): all tyres past a limit together per reason,
     * otherwise tyres sharing the reason and due point, and the age limits of
     * one set together at the soonest (so the title names the set). Each
     * group is titled by the tyre reminder's own helper.
     *
     * @return list<TyreDue>
     */
    private function tyreDues(User $user, Vehicle $vehicle, string $currency, bool $costs): array
    {
        $verdict = $this->tyres->verdict($vehicle, $user);
        $groups = [];
        foreach ($verdict->standings as $standing) {
            if (!$standing->isKnown() || ($standing->dueOn === null && $standing->dueKm === null)) {
                continue;
            }
            $setId = $standing->view->tyre->setId;
            $key = match (true) {
                $standing->status === DueStatus::Overdue => 'overdue|' . $standing->reason,
                // A set ages together: one item at its soonest limit, named by the set.
                $standing->reason === TyreStanding::AGE && $setId !== null => 'age|set|' . $setId,
                default => implode('|', [$standing->reason, $standing->dueOn?->format('Y-m-d') ?? '', $standing->dueKm ?? '']),
            };
            $groups[$key][] = $standing;
        }

        $dues = [];
        foreach ($groups as $named) {
            $first = $named[0];
            $overdue = $first->status === DueStatus::Overdue;
            $dueOn = null;
            foreach ($named as $standing) {
                if ($standing->dueOn !== null && ($dueOn === null || $standing->dueOn < $dueOn)) {
                    $dueOn = $standing->dueOn;
                }
            }
            $group = new TyreVerdict($first->status, $verdict->standings, $first->reason, $dueOn, $first->dueKm, $named);
            $dues[] = new TyreDue(
                title: $this->tyreTitles->title($user, $vehicle, $group),
                overdue: $overdue,
                dueOn: $dueOn,
                dueKm: $first->dueKm,
                projected: $first->reason === TyreStanding::WEAR,
                cost: $costs ? $this->fittingCost($vehicle, $named, $currency) : null,
            );
        }

        return $dues;
    }

    /**
     * The named tyres' shares of the records that fitted them; not known
     * unless every one of them has a share.
     *
     * @param list<TyreStanding> $named
     */
    private function fittingCost(Vehicle $vehicle, array $named, string $currency): ?Money
    {
        $ids = array_map(static fn (TyreStanding $s): int => $s->view->tyre->id, $named);
        $shares = $this->tyres->fittingShares($vehicle, $ids);
        if (count($shares) !== count($ids)) {
            return null;
        }
        $sum = Money::zero($currency);
        foreach ($shares as $share) {
            $sum = $sum->add(Money::of($share, $currency));
        }

        return ForecastCalculator::cost($sum->toDecimal(6), $currency);
    }
}
