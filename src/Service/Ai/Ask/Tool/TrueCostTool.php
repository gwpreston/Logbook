<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Report\ChangeLine;
use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostGap;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Support\Number\Decimal;

/**
 * `true_cost(vehicles?, period, by_year?)` (spec.md §7.35): each vehicle's
 * cost per distance split into its parts, with the change against the 12
 * months before; with by_year, the cost by calendar year and *What
 * changed*, its causes worked out by Logbook and handed over as sentences
 * and display strings, so the model words them and never computes them.
 * Core, and only for vehicles whose costs the user may see.
 */
final readonly class TrueCostTool implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private TrueCostService $trueCosts,
        private TrueCostWording $wording,
    ) {
    }

    public function name(): string
    {
        return 'true_cost';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'True cost per distance per vehicle: fuel, maintenance, insurance tax and MOT, other and depreciation '
            . 'per mile or km, with by_year the cost by calendar year and what changed from each year to the next, '
            . 'split exactly into causes. Quote its sentences; never work the causes out yourself. Use it for '
            . '"which car costs me most per mile" and "why has my car got more expensive?".',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Vehicle ids; all of them when left out.',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => [TrueCostRange::TwelveMonths->value, TrueCostRange::SinceBought->value],
                        'description' => 'Defaults to last_12_months.',
                    ],
                    'by_year' => ['type' => 'boolean', 'description' => 'Add each calendar year and what changed between years.'],
                ],
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
        [$vehicles, $named] = $this->kit->vehicles($user, $arguments);
        $range = TrueCostRange::chosen($arguments->choice('period', [
            TrueCostRange::TwelveMonths->value,
            TrueCostRange::SinceBought->value,
        ]));
        $byYear = ($arguments->values['by_year'] ?? false) === true;
        $hidden = array_values(array_filter($vehicles, fn (Vehicle $v): bool => !$this->kit->canSeeCosts($user, $v)));
        $results = $this->trueCosts->forVehicles($user, $vehicles, $this->kit->today($user));

        $figures = [];
        $rows = [];
        foreach ($results as $vtc) {
            $rows[] = $this->vehicle($vtc, $range, $byYear, $figures);
        }

        return new ToolResult(
            [
                'period' => ['key' => $range->value, 'label' => $this->kit->t('true_cost.range.' . $range->value)],
                'vehicles' => $rows,
                'note' => 'Each vehicle in its own currency, never converted. per_km is per kilometre; display is in the '
                    . 'user\'s unit. A negative part is money back (a gain in value, insurance payouts). A year under '
                    . '500 km is not compared.',
                ...($hidden === [] ? [] : [
                    'costs_not_shared' => array_map(fn (Vehicle $v): array => $this->kit->vehicleRef($v), $hidden),
                ]),
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.true_cost'),
                $this->kit->vehiclesLabel(array_map(static fn (VehicleTrueCost $v): Vehicle => $v->vehicle, $results), $named),
                $this->kit->t('true_cost.range.' . $range->value),
            ]),
            array_values(array_unique($figures)),
            $this->kit->link(
                '/reports/true-cost',
                count($results) === 1 ? ['vehicle' => (string) $results[0]->vehicle->id, 'include_archived' => '1'] : [],
            ),
            array_map(static fn (VehicleTrueCost $v): int => $v->vehicle->id, $results),
        );
    }

    /**
     * @param list<string> $figures the display strings the answer may quote
     * @return array<string, mixed>
     */
    private function vehicle(VehicleTrueCost $vtc, TrueCostRange $range, bool $byYear, array &$figures): array
    {
        $cost = $vtc->period($range);
        $row = [
            'vehicle' => $this->kit->vehicleRef($vtc->vehicle),
            'currency' => $vtc->currency,
            ...($cost === null
                ? ['per_distance' => null, 'note' => 'Not owned in this period.']
                : $this->cost($cost, $figures)),
        ];
        $change = $vtc->twelveMonthChange();
        if ($range === TrueCostRange::TwelveMonths && $change !== null) {
            $row['change_against_previous_12_months'] = $this->perKm($change, $vtc->currency, true);
            $figures[] = $this->wording->signed($change, $vtc->currency);
        }
        if ($byYear) {
            $row['years'] = array_map(fn (TrueCost $year): array => [
                'year' => $year->period->year,
                'label' => $this->wording->label($year),
                'partial' => $year->period->isPartial(),
                'comparable' => $year->isComparable(),
                ...$this->cost($year, $figures),
            ], $vtc->years);
            $row['what_changed'] = array_values(array_map(
                fn (CostChange $change): array => $this->change($change, $figures),
                $vtc->changes,
            ));
        }

        return $row;
    }

    /**
     * @param list<string> $figures
     * @return array<string, mixed>
     */
    private function cost(TrueCost $cost, array &$figures): array
    {
        if ($cost->perKm === null) {
            return [
                'per_distance' => null,
                'why_not' => $this->kit->t(($cost->gap ?? TrueCostGap::NoMileage)->messageKey()),
                'distance' => $this->kit->distance($cost->distanceKm),
            ];
        }
        $figures[] = $this->wording->rate($cost->perKm, $cost->currency);
        $parts = [];
        foreach ($cost->parts() as $part) {
            $rate = $cost->rate($part);
            if ($rate === null) {
                continue;
            }
            $amount = $cost->amount($part);
            $parts[] = [
                'part' => $part->value,
                'label' => $this->wording->part($part),
                ...$this->perKm($rate, $cost->currency),
                'amount' => $amount === null ? null : $this->kit->money($amount),
            ];
            $figures[] = $this->wording->rate($rate, $cost->currency);
        }

        return [
            'per_distance' => $this->perKm($cost->perKm, $cost->currency),
            'distance' => $this->kit->distance($cost->distanceKm),
            'parts' => $parts,
            'insurance_payouts' => $cost->payoutsPerKm === null ? null : [
                ...$this->perKm($cost->payoutsPerKm, $cost->currency),
                'amount' => $this->kit->money($cost->payouts),
            ],
            'running_costs_only' => $cost->isRunningOnly(),
            'depreciation_to' => $cost->depreciationTo?->format('Y-m-d'),
            'breakdown' => $this->wording->breakdown($cost),
        ];
    }

    /**
     * @param list<string> $figures
     * @return array<string, mixed>
     */
    private function change(CostChange $change, array &$figures): array
    {
        $currency = $change->after->currency;
        $total = $this->wording->signed($change->perKm, $currency);
        $figures[] = $total;

        return [
            'year' => $change->after->period->year,
            'label' => $this->wording->label($change->after),
            'against' => $this->wording->label($change->before),
            'total' => $this->perKm($change->perKm, $currency, true),
            'lines' => array_map(function (ChangeLine $line) use ($currency, &$figures): array {
                $figures[] = $this->wording->signed($line->perKm, $currency);

                return [
                    'cause' => $line->cause->value,
                    'part' => $line->part?->value,
                    'energy' => $line->energy?->value,
                    ...$this->perKm($line->perKm, $currency, true),
                    'sentence' => $this->wording->sentence($line, $currency),
                ];
            }, $change->lines),
            'note' => 'The lines add up exactly to the total; each display is rounded on its own.',
        ];
    }

    /**
     * @return array{per_km: string, display: string}
     */
    private function perKm(string $perKm, string $currency, bool $signed = false): array
    {
        return [
            'per_km' => Decimal::round($perKm, 6),
            'display' => $signed ? $this->wording->signed($perKm, $currency) : $this->wording->rate($perKm, $currency),
        ];
    }
}
