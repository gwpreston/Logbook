<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\GroupTotal;
use Logbook\Service\Report\MonthTotal;
use Logbook\Service\Report\VehicleCost;

/**
 * `costs(vehicles?, period, group_by?, category?)`: what was spent, per
 * currency, by category group, month or vehicle, with the distance
 * driven (spec.md §7.26, Reports §7.7).
 */
final readonly class Costs extends ReportTool implements AskTool
{
    private const array GROUP_BY = ['category', 'month', 'vehicle'];

    public function name(): string
    {
        return 'costs';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Money spent in a period: totals per currency, split by category (fuel, maintenance, '
            . 'compliance, other, and build for modifications), by month or by vehicle, plus distance driven. '
            . 'Leave vehicles out for all of them. Use category to count only one kind of cost, e.g. fuel. '
            . 'Modifications (build) are in the total but are not running costs: average_per_month and '
            . 'running_total leave them out.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Vehicle ids.'],
                    ...AskPeriod::SCHEMA,
                    'group_by' => ['type' => 'string', 'enum' => self::GROUP_BY],
                    'category' => ['type' => 'string', 'enum' => self::categories()],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $groupBy = $arguments->choice('group_by', self::GROUP_BY) ?? 'category';
        $category = CostGroup::tryFrom($arguments->choice('category', self::categories()) ?? '');
        [$report, $period, $counted, $hidden, $named] = $this->report($user, $arguments, $category);

        $currencies = array_map(fn (CurrencyReport $section): array => [
            'currency' => $section->currency,
            'total' => $this->kit->money($section->total),
            ...$this->running($section, $category),
            'entries' => $section->count,
            'distance' => $this->kit->distance($section->distanceKm),
            ...$this->breakdown($section, $groupBy),
        ], $report->isEmpty() ? [] : $report->currencies);

        $figures = [];
        foreach ($report->isEmpty() ? [] : $report->currencies as $section) {
            $figures[] = $this->kit->format->money($section->total);
        }

        return new ToolResult(
            [
                'period' => $period->toArray() + ['label' => $this->kit->periodLabel($period)],
                'vehicles' => array_map($this->kit->vehicleRef(...), $counted),
                'category' => $category?->value,
                'group_by' => $groupBy,
                'currencies' => $currencies,
                'nothing_spent' => $report->isEmpty(),
                ...$this->hiddenNote($hidden),
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.costs'),
                $this->kit->vehiclesLabel($counted, $named),
                $this->kit->periodLabel($period),
                $category === null ? null : $this->kit->t('expense.group.' . $category->value),
                $this->kit->t('ask.source.by_' . $groupBy),
            ]),
            $figures,
            $this->reportLink($period, $counted, $named, $category),
            array_map(static fn (Vehicle $v): int => $v->id, $counted),
        );
    }

    /**
     * Build spend (Phase 50.1) is in the total but never a running cost
     * (spec.md §7.7): the monthly average leaves it out, and says so.
     *
     * @return array<string, mixed>
     */
    private function running(CurrencyReport $section, ?CostGroup $category): array
    {
        if ($category === CostGroup::Build) {
            return [
                'average_per_month' => null,
                'note' => 'Modifications are not a running cost, so there is no monthly average for them.',
            ];
        }
        if (!$section->hasBuild()) {
            return ['average_per_month' => $this->kit->money($section->averagePerMonth)];
        }

        return [
            'running_total' => $this->kit->money($section->runningTotal()),
            'build_total' => $this->kit->money($section->group(CostGroup::Build)),
            'average_per_month' => $this->kit->money($section->averagePerMonth),
            'note' => 'The total includes modifications (build_total); they are not running costs, '
                . 'so average_per_month is running_total spread over the months.',
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function breakdown(CurrencyReport $section, string $groupBy): array
    {
        return match ($groupBy) {
            'month' => ['by_month' => array_map(fn (MonthTotal $m): array => [
                'month' => $m->month->format('Y-m'),
                'label' => $this->kit->format->month($m->month, false),
                'total' => $this->kit->money($m->total),
            ], $section->months)],
            'vehicle' => ['by_vehicle' => array_map(fn (VehicleCost $v): array => [
                'vehicle' => $this->kit->vehicleRef($v->vehicle),
                'total' => $this->kit->money($v->total),
                'entries' => $v->count,
                'distance' => $this->kit->distance($v->distanceKm),
            ], $section->vehicles)],
            default => ['by_category' => array_map(fn (GroupTotal $g): array => [
                'category' => $g->group->value,
                'label' => $this->kit->t('expense.group.' . $g->group->value),
                'total' => $this->kit->money($g->amount),
            ], $section->spentGroups())],
        };
    }

    /**
     * @return list<string>
     */
    private static function categories(): array
    {
        return array_map(static fn (CostGroup $g): string => $g->value, CostGroup::cases());
    }
}
