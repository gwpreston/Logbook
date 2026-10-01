<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * `ownership(vehicle)`: the cost of ownership since it was bought or first
 * logged (spec.md §7.26, Phase 14.2): running costs, depreciation, the
 * total and per distance and per month. Only with ViewCosts.
 */
final readonly class Ownership implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private OwnershipService $ownership,
        private OdometerService $odometer,
        private ValuationService $valuations,
        private AttentionSettingsStore $attentionSettings,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'ownership';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Lifetime cost of owning one vehicle: running costs, depreciation (purchase price against the '
            . 'latest value), the total, and the cost per distance and per month.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                ],
                'required' => ['vehicle'],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Reports);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        $link = $this->kit->link('/reports/ownership', ['vehicle' => (string) $vehicle->id, 'include_archived' => '1']);
        $source = $this->kit->source([$this->kit->t('ask.tool.ownership'), $vehicle->name()]);
        if (!$this->kit->canSeeCosts($user, $vehicle)) {
            return new ToolResult(
                [
                    'vehicle' => $this->kit->vehicleRef($vehicle),
                    'costs_not_shared' => true,
                    'note' => 'Costs for this vehicle are not shared with this user.',
                ],
                $source,
                [],
                null,
                [$vehicle->id],
            );
        }

        $today = $this->kit->today($user);
        $readings = $this->odometer->history($vehicle)->readings;
        $currency = $this->kit->currency($user, $vehicle);
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
        if ($cost === null) {
            return new ToolResult(
                [
                    'vehicle' => $this->kit->vehicleRef($vehicle),
                    'note' => 'Not enough has been logged to work out the cost of ownership.',
                ],
                $source,
                [],
                $link,
                [$vehicle->id],
            );
        }

        $money = fn (?Money $m): ?array => $m === null ? null : $this->kit->money($m);
        $perKm = fn (?string $value): ?array => $value === null ? null : [
            'per_km' => Decimal::round($value, 4),
            'display' => $this->kit->format->perDistance($value, $currency),
        ];
        $current = $depreciation->current;
        $figures = array_values(array_filter([
            $cost->total === null ? $this->kit->format->money($cost->running) : $this->kit->format->money($cost->total),
            $cost->perKm === null ? null : $this->kit->format->perDistance($cost->perKm, $currency),
            $cost->perMonth === null ? null : $this->kit->format->money($cost->perMonth),
        ]));

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'since' => [
                    'kind' => $cost->start->value,
                    'date' => $cost->period->from?->format('Y-m-d'),
                    'date_display' => $cost->period->from === null ? null : $this->kit->format->date($cost->period->from),
                ],
                'owned_for' => [
                    'years' => $cost->ownedFor->years,
                    'months' => $cost->ownedFor->months,
                    'display' => $this->kit->t($cost->ownedFor->labelKey(), [
                        'years' => $cost->ownedFor->years,
                        'months' => $cost->ownedFor->months,
                    ]),
                ],
                'distance' => $this->kit->distance($cost->distanceKm),
                'running_costs' => $money($cost->running),
                'running_costs_entries' => $cost->count,
                'depreciation' => $money($cost->depreciationCost),
                'depreciation_note' => $cost->depreciationCost === null
                    ? 'No depreciation without a purchase price and a current value.'
                    : 'Negative means the vehicle is worth more than was paid.',
                'current_value' => $current === null ? null : [
                    ...$this->kit->money(Money::of($current->amount, $currency)),
                    'date' => $current->date->format('Y-m-d'),
                ],
                'total' => $money($cost->total),
                'running_per_distance' => $perKm($cost->runningPerKm),
                'per_distance' => $perKm($cost->perKm),
                'per_distance_is_running_only' => $cost->perKmIsPartial,
                'per_month' => $money($cost->perMonth),
                'per_month_is_running_only' => $cost->perMonthIsPartial,
            ],
            $source,
            $figures,
            $link,
            [$vehicle->id],
        );
    }
}
