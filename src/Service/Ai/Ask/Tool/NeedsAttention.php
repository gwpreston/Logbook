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
use Logbook\Service\Attention\AttentionItem;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Attention\AttentionWording;

/**
 * `needs_attention(vehicles?)`: the current *Needs attention* items
 * (spec.md §7.26, §7.24): overdue work and paperwork, and data that looks
 * wrong. Read only: the stored reminders are not brought up to date first
 * (`sync: false`), so nothing is written; hidden checks stay hidden.
 */
final readonly class NeedsAttention implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private AttentionList $attention,
        private AttentionWording $wording,
    ) {
    }

    public function name(): string
    {
        return 'needs_attention';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'What needs attention now: overdue services, renewals and reminders, and records that look wrong '
            . '(odd odometer readings, economy drift, unusual prices or costs, stale mileage or valuations).',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Vehicle ids.'],
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
        $items = $this->attention->forVehicles($user, $vehicles, sync: false)->items;

        $rows = array_map(fn (AttentionItem $item): array => [
            'vehicle' => $this->kit->vehicleRef($item->vehicle),
            'kind' => $item->kind->value,
            'severity' => $item->severity()->value,
            'title' => $this->wording->title($item),
            'detail' => $this->wording->detail($item),
        ], array_slice($items, 0, ToolKit::LIST_CAP));
        $figures = array_map(
            fn (AttentionItem $item): string => $item->vehicle->name() . ': ' . $this->wording->title($item),
            array_slice($items, 0, 3),
        );
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;

        return new ToolResult(
            [
                'total_count' => count($items),
                'items' => $rows,
                'note' => 'Archived vehicles are not checked.',
            ],
            $this->kit->source([$this->kit->t('ask.tool.needs_attention'), $this->kit->vehiclesLabel($vehicles, $named)]),
            $figures,
            $one === null ? '/garage' : '/vehicles/' . $one->id,
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }
}
