<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Api\ApiKey;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * What each read endpoint returns (spec.md §7.20), from the same services
 * the pages use, as the Serializer's shapes. The access policy decides the
 * vehicles and whether amounts are shown; switched-off modules leave out
 * their fields. Formatted text (`display`, names) is in the key owner's
 * language and units: ApiAuthMiddleware applies them to the request.
 */
final readonly class ApiReader
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TyreService $tyres,
        private ComingUp $comingUp,
        private ForecastWording $forecastWording,
        private ReminderService $reminders,
        private DueCounter $dueCounter,
        private ReminderSync $reminderSync,
        private ReminderSettingsStore $reminderSettings,
        private ReportService $reports,
        private DisplayFormatter $format,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $user, ApiKey $key): array
    {
        $preferences = $user->preferences;

        return [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->displayName,
                'locale' => $preferences->locale,
                'timezone' => $preferences->timezone,
                'currency' => $preferences->currency,
                'distance_unit' => $preferences->distanceUnit->value,
                'volume_unit' => $preferences->volumeUnit->value,
                'consumption_unit' => $preferences->consumptionUnit->value,
                'depth_unit' => $preferences->depthUnit->value,
            ],
            'key' => [
                'id' => $key->id,
                'name' => $key->name,
                'scope' => $key->scope->value,
                'created_at' => Serializer::instant($key->createdAt),
            ],
            'modules' => $this->features->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vehicles(User $user, VehicleScope $scope): array
    {
        $vehicles = $this->vehicles->listFleet($user, $scope !== VehicleScope::Active);
        if ($scope === VehicleScope::Archived) {
            $vehicles = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => $v->isArchived()));
        }

        return array_map(fn (Vehicle $vehicle): array => $this->vehicle($user, $vehicle), $vehicles);
    }

    /**
     * @return array<string, mixed>
     */
    public function vehicle(User $user, Vehicle $vehicle): array
    {
        return Serializer::vehicle($vehicle, $this->vehicles->currencyFor($user, $vehicle), $this->costs($user, $vehicle));
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function fuel(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $history = $this->fuel->history($vehicle);
        $page = $query->page(
            $history->fills,
            static fn (FillEconomy $fill): array => [$fill->entry->data->filledAt, $fill->entry->id],
        );

        return [
            'items' => array_map(
                fn (FillEconomy $fill): array => $this->fuelEntry($user, $vehicle, $fill->entry, $history),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * One fill-up as the fuel list shows it.
     *
     * @return array<string, mixed>
     */
    public function fuelEntry(User $user, Vehicle $vehicle, FuelEntry $entry, ?FuelHistory $history = null): array
    {
        $history ??= $this->fuel->history($vehicle);
        $fill = null;
        foreach ($history->fills as $candidate) {
            if ($candidate->entry->id === $entry->id) {
                $fill = $candidate;
            }
        }
        $check = $this->fuel->checks($history)->for($entry->id);

        return Serializer::fuelEntry(
            $fill->entry ?? $entry,
            $fill,
            $check,
            $this->vehicles->currencyFor($user, $vehicle),
            $this->costs($user, $vehicle),
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function odometer(Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page(
            $this->odometer->history($vehicle)->readings,
            static fn (OdometerReading $reading): array => [$reading->recordedAt, $reading->id],
        );

        return ['items' => array_map(Serializer::odometerReading(...), $page['items']), 'cursor' => $page['cursor']];
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function maintenance(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page(
            $this->maintenance->history($vehicle)->entries,
            static fn (MaintenanceEntry $entry): array => [$entry->data->performedOn, $entry->id],
        );
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $costs = $this->costs($user, $vehicle);

        return [
            'items' => array_map(
                static fn (MaintenanceEntry $entry): array => Serializer::maintenanceEntry($entry, $currency, $costs),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * Documents by start date (or expiry, or when added).
     *
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function documents(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page($this->documentStates($user, $vehicle), static function (DocumentState $state): array {
            $data = $state->document->data;

            return [$data->startOn ?? $data->expiryOn ?? $state->document->createdAt, $state->document->id];
        });
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $costs = $this->costs($user, $vehicle);

        return [
            'items' => array_map(
                static fn (DocumentState $state): array => Serializer::document($state, $currency, $costs),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function expenses(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page(
            $this->expenses->entries($vehicle),
            static fn (ExpenseEntry $entry): array => [$entry->data->spentOn, $entry->id],
        );
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        return [
            'items' => array_map(
                static fn (ExpenseEntry $entry): array => Serializer::expense($entry, $currency),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * Every tyre, fitted first by position, then stored, then retired
     * (not paged: a vehicle has a handful).
     *
     * @return list<array<string, mixed>>
     */
    public function tyres(User $user, Vehicle $vehicle): array
    {
        $overview = $this->tyres->overview($vehicle, $user);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $costs = $this->costs($user, $vehicle);

        $views = $overview->fittedTyres();
        foreach ($overview->stored as $group) {
            array_push($views, ...$group->tyres);
        }
        array_push($views, ...$overview->retired);

        return array_map(
            static fn ($view): array => Serializer::tyre($view, $overview->verdict->standing($view->tyre->id), $currency, $costs),
            $views,
        );
    }

    /**
     * *Coming up* over the next 12 months (spec.md §7.18), overdue first.
     *
     * @param list<Vehicle> $vehicles
     * @return list<array<string, mixed>>
     */
    public function upcoming(User $user, array $vehicles): array
    {
        $active = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => !$v->isArchived()));
        if ($active === []) {
            return [];
        }
        $costs = [];
        foreach ($active as $vehicle) {
            $costs[$vehicle->id] = $this->costs($user, $vehicle);
        }

        return array_map(
            fn (ForecastItem $item): array => ['name' => $this->forecastWording->title($item)]
                + Serializer::upcoming($item, $costs[$item->vehicle->id] ?? false),
            $this->comingUp->forecast($user, $active)->items(),
        );
    }

    /**
     * Open reminders, most urgent first (spec.md §7.6).
     *
     * @return list<array<string, mixed>>
     */
    public function reminders(User $user, ?Vehicle $vehicle, ?ReminderStatus $status): array
    {
        $overview = $this->reminders->overview($user);
        $entries = array_filter(
            $overview->open,
            static fn (ReminderEntry $entry): bool => ($vehicle === null || $entry->vehicle->id === $vehicle->id)
                && ($status === null || $entry->reminder->status === $status),
        );

        return array_values(array_map(
            static fn (ReminderEntry $entry): array => Serializer::reminder($entry, $overview->today),
            $entries,
        ));
    }

    /**
     * The per-vehicle summary for sensors (spec.md §7.20).
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, Vehicle $vehicle): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $costs = $this->costs($user, $vehicle);
        $display = [];

        $latest = $this->odometer->history($vehicle)->latest();
        $summary = [
            'vehicle_id' => $vehicle->id,
            'name' => $vehicle->name(),
            'status' => $vehicle->status->value,
            'distance_unit' => Serializer::DISTANCE_UNIT,
            'odometer' => $latest === null ? null : [
                'value' => $latest->readingKm,
                'recorded_at' => Serializer::instant($latest->recordedAt),
                'source' => $latest->source->value,
            ],
        ];
        $display['odometer'] = $latest === null ? null : $this->format->distance($latest->readingKm);

        if ($this->features->isEnabled(Feature::Fuel)) {
            $history = $this->fuel->history($vehicle);
            $series = [];
            foreach (EnergyKind::cases() as $kind) {
                $economy = $history->summary($kind);
                $series[$kind->value] = $economy === null ? null : Serializer::economySummary($economy, $costs);
            }
            $last = $history->latest();
            $summary['fuel'] = $series + [
                'last_fill_up' => $last === null ? null : $this->fuelEntry($user, $vehicle, $last->entry, $history),
            ];

            $primary = Fuel::defaultFor($vehicle->data->fuelType)->kind();
            $economy = $history->summary($primary);
            $display['economy'] = $economy === null || !$economy->hasEconomy()
                ? null
                : $this->format->economy(
                    $economy->measuredDistanceKm,
                    $economy->measuredVolume,
                    $primary === EnergyKind::Electric,
                );
            $display['last_fill_up'] = $last === null ? null : $this->format->dateTime($last->entry->data->filledAt);
        }

        if ($costs) {
            $period = ReportPeriod::preset(ReportRange::TwelveMonths, $today);
            $report = $this->reports->forVehicles($user, new ReportFilter($period, $vehicle->id), [$vehicle]);
            $section = $report->currencies[0] ?? null;
            $perKm = $section?->costPerKm;
            $summary['costs'] = [
                'currency' => $currency,
                'from' => Serializer::date($report->period->from),
                'to' => Serializer::date($report->period->to),
                'total' => ($section?->total)?->toDecimal(3) ?? '0.000',
                'distance' => $section?->distanceKm,
                'cost_per_distance' => $perKm === null ? null : Decimal::round($perKm, Serializer::PER_KM_SCALE),
            ];
            $display['cost_per_distance'] = $perKm === null ? null : $this->format->perDistance($perKm, $currency);
        }

        $next = $vehicle->isArchived() ? null : ($this->comingUp->forecast($user, [$vehicle])->next(1)[0] ?? null);
        $summary['next_due'] = $next === null
            ? null
            : ['name' => $this->forecastWording->title($next)] + Serializer::upcoming($next, $costs);
        $display['next_due'] = $next === null ? null : $this->nextDueText($next);

        if ($this->features->isEnabled(Feature::Reminders)) {
            // As the Reminders page does: bring the stored reminders up to date first.
            $this->reminderSync->sync($user);
            $counts = $this->dueCounter->counts($user)->forVehicle($vehicle->id);
            $summary['reminders'] = ['overdue' => $counts->overdue, 'due' => $counts->due];
        }

        if ($this->features->isEnabled(Feature::Compliance)) {
            $summary['documents'] = array_values(array_map(
                Serializer::documentExpiry(...),
                array_filter(
                    $this->documentStates($user, $vehicle, $today),
                    static fn (DocumentState $s): bool => $s->status->isCurrent(),
                ),
            ));
        }

        if ($this->features->isEnabled(Feature::Tyres)) {
            $verdict = $this->tyres->hasTyres($vehicle) ? $this->tyres->verdict($vehicle, $user) : null;
            $summary['tyres'] = $verdict === null ? null : [
                'status' => $verdict->status->value,
                'reason' => $verdict->reason,
                'due_on' => Serializer::date($verdict->dueOn),
                'due_odometer' => $verdict->dueKm,
                'worn' => $verdict->isWorn(),
            ];
        }

        $summary['display'] = $display;

        return $summary;
    }

    /**
     * "MOT · 12 Mar 2027", "Oil change · at 60,000 mi"
     */
    private function nextDueText(ForecastItem $item): string
    {
        $when = match (true) {
            $item->dueOn !== null => $this->format->date($item->dueOn),
            $item->dueKm !== null => $this->format->distance($item->dueKm),
            default => null,
        };

        return $this->forecastWording->title($item) . ($when === null ? '' : ' · ' . $when);
    }

    /**
     * @return list<DocumentState>
     */
    private function documentStates(User $user, Vehicle $vehicle, ?DateTimeImmutable $today = null): array
    {
        $today ??= LocalTime::today($this->clock, $user->preferences->timeZone());

        return $this->compliance->states($vehicle, $today, $this->reminderSettings->reminderPreferences($user->id)->documentDays);
    }

    public function costs(User $user, Vehicle $vehicle): bool
    {
        return $this->access->can($user, VehicleAbility::ViewCosts, $vehicle);
    }

    /**
     * Whether a list endpoint's vehicle filter (`?vehicle=`) names a
     * vehicle the user can view; null when it does not.
     */
    public function visibleVehicle(User $user, int $id): ?Vehicle
    {
        foreach ($this->vehicles->listFleet($user, true) as $vehicle) {
            if ($vehicle->id === $id) {
                return $vehicle;
            }
        }

        return null;
    }

    /**
     * @return list<Vehicle>
     */
    public function activeVehicles(User $user): array
    {
        return $this->vehicles->listFleet($user);
    }
}
