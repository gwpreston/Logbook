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
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Support\Date\LocalTime;

/**
 * `coming_up(vehicles?, horizon_months?)`: what falls due, from *Coming
 * up* (spec.md §7.26, §7.18): overdue items, then by date within the
 * horizon, then items that can't be dated yet. Costs (last time's price)
 * only for vehicles whose costs the user may see, as the page.
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
            . 'reminders, with due dates, due odometer and last time\'s cost. Overdue items come first.',
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
        $end = LocalTime::addMonths($today, $months);

        $items = array_values(array_filter(
            $forecast->items(),
            static fn (ForecastItem $item): bool => $item->overdue || $item->dueOn === null || $item->dueOn <= $end,
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
            ...($item->cost === null ? [] : ['last_cost' => $this->kit->money($item->cost)]),
        ], $shown);

        $figures = array_map(
            fn (ForecastItem $item): string => $this->wording->title($item)
                . ($item->dueOn === null ? '' : ' · ' . $this->kit->format->date($item->dueOn)),
            array_slice($items, 0, 3),
        );
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;

        return new ToolResult(
            [
                'today' => $today->format('Y-m-d'),
                'horizon_end' => $end->format('Y-m-d'),
                'total_count' => count($items),
                'items' => $rows,
                'note' => 'Archived vehicles have nothing coming up. A cost is the price paid last time, where known.',
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
}
