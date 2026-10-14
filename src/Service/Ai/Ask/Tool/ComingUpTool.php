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
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastTotals;
use Logbook\Service\Forecast\ForecastWording;

/**
 * `coming_up(vehicles?, horizon_months?)`: what falls due, from *Coming
 * up* (spec.md §7.26, §7.18): overdue items, then by date within the
 * horizon, then items that can't be dated yet. Costs (last time's price)
 * only for vehicles whose costs the user may see, as the page. The horizon
 * is calendar months as the page's (this month and the n − 1 after), and
 * the *Next 3 months* total comes worked out (Phase 44), so the model
 * never adds it up.
 */
final readonly class ComingUpTool implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private ComingUp $comingUp,
        private ForecastWording $wording,
    ) {
    }

    public function name(): string
    {
        return 'coming_up';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'What falls due next: services by schedule, document renewals (insurance, MOT, ...), tyres and '
            . 'reminders, with due dates, due odometer and last time\'s cost. Overdue items come first. '
            . 'Also the total expected over the next 3 months (this month and the next two, fuel included), '
            . 'per currency.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Vehicle ids.'],
                    'horizon_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
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
        $months = $arguments->int('horizon_months', 1, 12) ?? 12;
        $forecast = $this->comingUp->forecast($user, $vehicles);
        $today = $forecast->today();
        $horizon = $forecast->horizon;
        // Calendar months, as the page's sections and totals (spec.md §7.18, #381).
        $end = $horizon->months()[$months - 1]->modify('last day of this month');

        $items = array_values(array_filter(
            $forecast->items(),
            static fn (ForecastItem $item): bool => $item->overdue || $item->dueOn === null
                || ($horizon->monthIndex($item->dueOn) ?? $months) < $months,
        ));
        $shown = array_slice($items, 0, ToolKit::LIST_CAP);

        $rows = array_map(fn (ForecastItem $item): array => [
            'vehicle' => $this->kit->vehicleRef($item->vehicle),
            'what' => $this->wording->title($item),
            'kind' => $item->source->value,
            'due_on' => $item->dueOn?->format('Y-m-d'),
            'due_on_display' => $item->dueOn === null ? null : $this->kit->format->date($item->dueOn),
            'date_is_estimate' => $item->projected,
            'due_odometer' => $this->kit->distance($item->dueKm),
            'overdue' => $item->overdue,
            ...($item->cost === null ? [] : [
                'last_cost' => $this->kit->money($item->cost),
                'last_cost_as_of' => $item->costAsOf?->toArray(),
            ]),
        ], $shown);

        $soon = array_map(static fn (ForecastTotals $t): ForecastTotals => $t->soon(), $forecast->totals);
        $figures = array_map(
            fn (ForecastItem $item): string => $this->wording->title($item)
                . ($item->dueOn === null ? '' : ' · ' . $this->kit->format->date($item->dueOn)),
            array_slice($items, 0, 3),
        );
        $soonFigures = array_values(array_filter(array_map($this->soonDisplay(...), $soon)));
        array_unshift($figures, ...$soonFigures);
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;

        return new ToolResult(
            [
                'today' => $today->format('Y-m-d'),
                'horizon_end' => $end->format('Y-m-d'),
                'total_count' => count($items),
                'items' => $rows,
                'next_3_months' => array_map($this->soonRow(...), $soon),
                'note' => 'Archived vehicles have nothing coming up. A cost is the price paid last time, where known. '
                    . 'next_3_months is this month (overdue items included) and the next two, planned and fuel; '
                    . 'with items_without_cost above 0 it is "at least". Quote its display unchanged; a null display '
                    . 'means nothing in those months has a known cost.',
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.coming_up'),
                $this->kit->vehiclesLabel($vehicles, $named),
                $this->kit->t('ask.source.horizon', ['months' => $months]),
            ]),
            $figures,
            $this->kit->link('/upcoming', $one === null ? [] : ['vehicle' => (string) $one->id]),
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function soonRow(ForecastTotals $totals): array
    {
        return [
            'currency' => $totals->currency,
            'total' => $this->kit->money($totals->total()),
            'planned' => $this->kit->money($totals->planned()),
            'fuel' => $totals->hasFuel ? $this->kit->money($totals->fuel()) : null,
            'at_least' => $totals->isAtLeast(),
            'items_without_cost' => $totals->unknown(),
            'display' => $this->soonDisplay($totals),
        ];
    }

    /**
     * "about £620" or "at least £620", as the page shows it; null while
     * nothing in those months has a known cost, where the page shows "—" (#384).
     */
    private function soonDisplay(ForecastTotals $totals): ?string
    {
        if ($totals->total()->isZero()) {
            return null;
        }

        return $this->kit->t(
            $totals->isAtLeast() ? 'coming_up.at_least' : 'coming_up.about',
            ['amount' => $this->kit->format->money($totals->total())],
        );
    }
}
