<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\VehicleCost;
use Logbook\Support\Number\Decimal;

/**
 * `cost_per_distance(vehicles?, period)`: running cost per mile or km, per
 * vehicle and for the fleet in each currency, with the distance behind it
 * (spec.md §7.26, Reports §7.7). Vehicles come cheapest first.
 */
final readonly class CostPerDistance extends ReportTool implements AskTool
{
    public function name(): string
    {
        return 'cost_per_distance';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Running cost per distance (all costs divided by distance driven) in a period, per vehicle and '
            . 'for all vehicles together in each currency. Use it for "which car costs most per mile".',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Vehicle ids.'],
                    ...AskPeriod::SCHEMA,
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        [$report, $period, $counted, $hidden, $named] = $this->report($user, $arguments);
        $figures = [];
        $currencies = [];
        foreach ($report->currencies as $section) {
            $rows = $section->vehicles;
            usort($rows, static fn (VehicleCost $a, VehicleCost $b): int => match (true) {
                $a->costPerKm === null && $b->costPerKm === null => 0,
                $a->costPerKm === null => 1,
                $b->costPerKm === null => -1,
                default => Decimal::compare($a->costPerKm, $b->costPerKm),
            });
            $currencies[] = [
                'currency' => $section->currency,
                'all_vehicles' => $this->perDistance($section->currency, $section->costPerKm, $section->distanceKm, $section),
                'vehicles' => array_map(fn (VehicleCost $row): array => [
                    'vehicle' => $this->kit->vehicleRef($row->vehicle),
                    'total' => $this->kit->money($row->total),
                    ...$this->perDistance($section->currency, $row->costPerKm, $row->distanceKm),
                ], $rows),
            ];
            foreach ($rows as $row) {
                if ($row->costPerKm !== null) {
                    $figures[] = $row->vehicle->name() . ': '
                        . $this->kit->format->perDistance($row->costPerKm, $section->currency);
                }
            }
        }

        return new ToolResult(
            [
                'period' => $period->toArray() + ['label' => $this->kit->periodLabel($period)],
                'currencies' => $currencies,
                'note' => 'A vehicle without a cost per distance had no distance logged in the period.',
                ...$this->hiddenNote($hidden),
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.cost_per_distance'),
                $this->kit->vehiclesLabel($counted, $named),
                $this->kit->periodLabel($period),
            ]),
            $figures,
            $this->reportLink($period, $counted, $named, null),
            array_map(static fn (Vehicle $v): int => $v->id, $counted),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function perDistance(string $currency, ?string $perKm, ?string $km, ?CurrencyReport $section = null): array
    {
        return [
            ...($section === null ? [] : ['total' => $this->kit->money($section->total)]),
            'distance' => $this->kit->distance($km),
            'cost_per_km' => $perKm === null ? null : Decimal::round($perKm, 4),
            'display' => $perKm === null ? null : $this->kit->format->perDistance($perKm, $currency),
        ];
    }
}
