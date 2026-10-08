<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Maintenance\MaintenanceScheduleNotFound;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Valuation\ValuationNotFound;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Psr\Clock\ClockInterface;

/**
 * A vehicle's schedules, valuations and ownership figures over the API
 * (spec.md §7.20 *Phase 39*), from the services the Maintenance tab, the
 * valuations list and the overview's ownership card use.
 */
final readonly class ApiVehicleFigures
{
    private const int SCALE = 6;

    public function __construct(
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private ReminderSettingsStore $reminderSettings,
        private ValuationService $valuations,
        private OwnershipService $ownership,
        private AttentionSettingsStore $attentionSettings,
        private VehicleService $vehicles,
        private DisplayFormatter $format,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every schedule with its due state, most urgent first, as the
     * Maintenance tab (the owner's lead times, the key user's today).
     *
     * @return list<array<string, mixed>>
     */
    public function schedules(User $user, Vehicle $vehicle): array
    {
        return array_map(Serializer::schedule(...), $this->scheduleStates($user, $vehicle));
    }

    /**
     * @throws ApiProblem 404 for a schedule the vehicle doesn't have
     */
    public function schedule(User $user, Vehicle $vehicle, int $id): ApiEntry
    {
        try {
            $stored = $this->schedules->get($vehicle, $id);
        } catch (MaintenanceScheduleNotFound) {
            throw ApiProblem::notFound('The vehicle has no such schedule.');
        }
        foreach ($this->scheduleStates($user, $vehicle) as $state) {
            if ($state->schedule->id === $id) {
                return new ApiEntry(Serializer::schedule($state), EntityTag::of($stored));
            }
        }
        throw new \LogicException('A schedule just read is missing from its vehicle.');
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     */
    public function valuations(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page(
            $this->valuations->forVehicle($vehicle),
            static fn (VehicleValuation $valuation): array => [$valuation->data->valuedOn, $valuation->id],
        );
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        return [
            'items' => array_map(
                static fn (VehicleValuation $valuation): array => Serializer::valuation($valuation, $currency),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * @throws ApiProblem 404 for a valuation the vehicle doesn't have
     */
    public function valuation(User $user, Vehicle $vehicle, int $id): ApiEntry
    {
        try {
            $valuation = $this->valuations->get($vehicle, $id);
        } catch (ValuationNotFound) {
            throw ApiProblem::notFound('The vehicle has no such valuation.');
        }

        return new ApiEntry(
            Serializer::valuation($valuation, $this->vehicles->currencyFor($user, $vehicle)),
            EntityTag::of($valuation),
        );
    }

    /**
     * Phase 14.2's figures, as the overview's ownership card and Ask's
     * `ownership` tool work them out: the running costs over the whole
     * ownership, the purchase and current value, and depreciation or the
     * state that stops it.
     *
     * @return array<string, mixed>
     */
    public function ownership(User $user, Vehicle $vehicle): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $readings = $this->odometer->history($vehicle)->readings;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        // The owner's threshold for the stale-value hint, as Needs attention uses (spec.md §7.24).
        $depreciation = Depreciation::of(
            $vehicle,
            $this->valuations->forVehicle($vehicle),
            $readings,
            $today,
            $user->preferences->timeZone(),
            $currency,
            $this->attentionSettings->thresholds($vehicle->userId)->valuationMonths,
        );
        $cost = $this->ownership->forVehicle($user, $vehicle, $readings, $depreciation, $today);
        $current = $depreciation->current;
        $money = static fn (?Money $m): ?string => $m?->toDecimal(Serializer::QUANTITY_SCALE);

        return [
            'vehicle_id' => $vehicle->id,
            'currency' => $currency,
            'since' => $cost?->period->from === null ? null : Serializer::date($cost->period->from),
            'distance_km' => Serializer::dec($cost?->distanceKm, Serializer::QUANTITY_SCALE),
            'distance_unit' => Serializer::DISTANCE_UNIT,
            'running_costs' => $cost === null ? null : $money($cost->running),
            'running_costs_entries' => $cost?->count,
            'purchase_price' => Serializer::dec($vehicle->data->purchasePrice, Serializer::QUANTITY_SCALE),
            'purchase_date' => Serializer::date($vehicle->data->purchaseDate),
            'current_value' => $current === null ? null : [
                'amount' => Serializer::dec($current->amount, Serializer::QUANTITY_SCALE),
                'on' => Serializer::date($current->date),
                'kind' => $current->kind->value,
                'source' => $current->source,
            ],
            'depreciation' => [
                'state' => $depreciation->state->value,
                'change' => Serializer::dec($depreciation->change, Serializer::QUANTITY_SCALE),
                'fraction' => Serializer::dec($depreciation->fraction, self::SCALE),
                'per_year' => Serializer::dec($depreciation->perYear, Serializer::QUANTITY_SCALE),
                'per_km' => Serializer::dec($depreciation->perKm, self::SCALE),
            ],
            'valuation_stale_months' => $depreciation->staleMonths,
            'total' => $cost === null ? null : $money($cost->total),
            'per_km' => Serializer::dec($cost?->perKm, self::SCALE),
            'per_km_is_running_only' => $cost?->perKmIsPartial,
            'per_month' => $cost === null ? null : $money($cost->perMonth),
            'per_month_is_running_only' => $cost?->perMonthIsPartial,
            'display' => [
                'running_costs' => $cost === null ? null : $this->format->money($cost->running),
                'current_value' => $current === null ? null : $this->format->money($current->amount, $currency),
                'depreciation' => $depreciation->change === null
                    ? null
                    : $this->format->money($depreciation->change, $currency),
                'depreciation_percent' => $depreciation->fraction === null ? null : $this->format->percent($depreciation->fraction),
                'per_distance' => $cost?->perKm === null ? null : $this->format->perDistance($cost->perKm, $currency),
                'per_month' => $cost?->perMonth === null ? null : $this->format->money($cost->perMonth),
            ],
        ];
    }

    /**
     * @return list<ScheduleState>
     */
    private function scheduleStates(User $user, Vehicle $vehicle): array
    {
        // The owner's lead times, as the vehicle's reminders and the Maintenance tab use (Phase 19).
        $lead = $this->reminderSettings->reminderPreferences($vehicle->userId);

        return $this->schedules->states(
            $vehicle,
            LocalTime::today($this->clock, $user->preferences->timeZone()),
            $this->odometer->history($vehicle),
            $lead->scheduleDays,
            $lead->scheduleKm,
        );
    }
}
