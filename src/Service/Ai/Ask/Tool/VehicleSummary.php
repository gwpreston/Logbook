<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Number\Decimal;

/**
 * `vehicle_summary(vehicle)`: what the vehicle page shows at the top
 * (spec.md §7.26): odometer, age, economy, the last 12 months' running
 * cost (with ViewCosts) and what is due next. Built from the services
 * underneath, so nothing is synced or written.
 */
final readonly class VehicleSummary implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private OdometerService $odometer,
        private FuelService $fuel,
        private ReportService $reports,
        private ComingUp $comingUp,
        private ForecastWording $wording,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'vehicle_summary';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'An overview of one vehicle: latest odometer reading, age, fuel economy, running cost per distance '
            . 'over the last 12 months, and the next thing due.',
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
        return true;
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        $today = $this->kit->today($user);
        $currency = $this->kit->currency($user, $vehicle);
        $costs = $this->kit->canSeeCosts($user, $vehicle);
        $figures = [];

        $latest = $this->odometer->history($vehicle)->latest();
        $odometer = $latest === null ? null : [
            'km' => $latest->readingKm,
            'display' => $this->kit->format->distance($latest->readingKm),
            'date' => $this->kit->format->instantDate($latest->recordedAt),
        ];
        if ($odometer !== null) {
            $figures[] = $odometer['display'];
        }

        $age = VehicleAge::of($vehicle, $today);
        $data = [
            'vehicle' => $this->kit->vehicleRow($vehicle),
            'odometer' => $odometer,
            'age' => $age === null ? null : [
                'years' => $age->years,
                'months' => $age->months,
                'display' => $this->kit->t($age->labelKey(), ['years' => $age->years, 'months' => $age->months]),
            ],
        ];

        if ($this->features->isEnabled(Feature::Fuel)) {
            $kind = Fuel::defaultFor($vehicle->data->fuelType)->kind();
            $economy = $this->fuel->history($vehicle)->summary($kind);
            $data['economy'] = $economy === null || !$economy->hasEconomy() ? null : [
                'distance_km' => $economy->measuredDistanceKm,
                'volume' => $economy->measuredVolume,
                'unit' => match ($kind) {
                    EnergyKind::Liquid => 'litres',
                    EnergyKind::Electric => 'kwh',
                    EnergyKind::Gas => 'kg',
                },
                'display' => $this->kit->format->economy(
                    $economy->measuredDistanceKm,
                    $economy->measuredVolume,
                    $kind,
                ),
            ];
            if ($data['economy'] !== null) {
                $figures[] = $data['economy']['display'];
            }
        }

        if ($costs) {
            $period = ReportPeriod::preset(ReportRange::TwelveMonths, $today);
            $report = $this->reports->forVehicles($user, new ReportFilter($period, $vehicle->id, true), [$vehicle]);
            $section = $report->currencies[0] ?? null;
            $perKm = $section?->costPerKm;
            $data['running_cost_last_12_months'] = [
                'from' => $report->period->from?->format('Y-m-d'),
                'to' => $report->period->to->format('Y-m-d'),
                'total' => $section === null ? null : $this->kit->money($section->total),
                'distance' => $this->kit->distance($section?->distanceKm),
                'cost_per_km' => $perKm === null ? null : Decimal::round($perKm, 4),
                'display' => $perKm === null ? null : $this->kit->format->perDistance($perKm, $currency),
            ];
            if ($perKm !== null) {
                $figures[] = $this->kit->format->perDistance($perKm, $currency);
            }
        } else {
            $data['note'] = 'Costs for this vehicle are not shared with this user.';
        }

        $next = $vehicle->isArchived() ? null : ($this->comingUp->forecast($user, [$vehicle])->next(1)[0] ?? null);
        $data['next_due'] = $next === null ? null : [
            'title' => $this->wording->title($next),
            'due_on' => $next->dueOn?->format('Y-m-d'),
            'due_on_display' => $next->dueOn === null ? null : $this->kit->format->date($next->dueOn),
            'due_odometer' => $this->kit->distance($next->dueKm),
            'overdue' => $next->overdue,
            'estimated' => $next->projected,
            ...($next->cost === null ? [] : ['cost' => $this->kit->money($next->cost)]),
        ];

        return new ToolResult(
            $data,
            $this->kit->source([$this->kit->t('ask.tool.vehicle_summary'), $vehicle->name()]),
            $figures,
            '/vehicles/' . $vehicle->id,
            [$vehicle->id],
        );
    }
}
