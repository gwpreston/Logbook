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
use Logbook\Service\Insights\Insight;
use Logbook\Service\Insights\InsightsService;

/**
 * `computed_insights(vehicles?)` (spec.md §7.26 *Tools*, Phase 42): every
 * computed insight (§7.8) the user would see for those vehicles, all of
 * them and in order, as the Insights page shows them, with the raw figures
 * behind *Fuel saving* and *Economy up*. "How much could I save on fuel?"
 * is answered from it, worked out by Logbook, never by the model.
 */
final readonly class ComputedInsights implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private InsightsService $insights,
    ) {
    }

    public function name(): string
    {
        return 'computed_insights';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'The insights Logbook works out itself, as the Insights page shows them: shopping around, how much the user '
            . 'could save a year on fuel by filling at the cheapest station nearby, business mileage, the cheapest vehicle '
            . 'to run, finance equity, and economy that has improved. Each with its figures, already worked out.',
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
        $active = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => !$v->isArchived()));
        $found = $this->insights->forVehicles($user, $active, !$named, $this->kit->today($user));
        $names = [];
        foreach ($active as $vehicle) {
            $names[$vehicle->id] = $vehicle;
        }

        $rows = array_map(fn (Insight $insight): array => array_filter([
            'kind' => $insight->kind->value,
            'vehicle' => $insight->vehicleId !== null && isset($names[$insight->vehicleId])
                ? $this->kit->vehicleRef($names[$insight->vehicleId])
                : null,
            'title' => $this->kit->t($insight->title, $insight->titleParams),
            'body' => $this->kit->t($insight->body, $insight->bodyParams),
            'figures' => $insight->figures === [] ? null : $insight->figures,
        ], static fn (mixed $value): bool => $value !== null), array_slice($found, 0, ToolKit::LIST_CAP));

        return new ToolResult(
            [
                'total_count' => count($found),
                'insights' => $rows,
                'note' => 'Worked out by Logbook from its own figures; archived vehicles are left out.',
            ],
            $this->kit->source([$this->kit->t('ask.tool.computed_insights'), $this->kit->vehiclesLabel($vehicles, $named)]),
            array_map(
                fn (Insight $insight): string => $this->kit->t($insight->title, $insight->titleParams),
                array_slice($found, 0, 3),
            ),
            '/insights',
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }
}
