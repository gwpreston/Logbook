<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartService;
use Logbook\Support\Money\Money;

/**
 * `modifications(vehicles?, state?)` (spec.md §7.26, §7.39, Phase 50.2, #441): the build work on the
 * user's vehicles, with the parts it fitted and took off. Current ones by default. A cost needs
 * `ViewCosts`, and build spend is given as its own figure, never as a running cost.
 */
final readonly class Modifications implements AskTool
{
    private const array STATES = ['current', 'reverted', 'all'];

    public function __construct(
        private ToolKit $kit,
        private ModificationService $modifications,
        private PartService $parts,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'modifications';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Modifications on the user\'s vehicles (an exhaust, coilovers, an ECU tune, wheels): title, category, date '
            . 'and mileage, who fitted it, whether and when it was declared to the insurer, whether it was reverted, '
            . 'and the parts it fitted and took off (names, brands, part numbers). Current ones unless a state is '
            . 'given, newest first. The cost is only given where the user may see costs, as build_total, and is never '
            . 'a running cost: it is not in any cost per distance.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Vehicle ids; all when left out.',
                    ],
                    'state' => ['type' => 'string', 'enum' => self::STATES],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Parts);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        [$vehicles, $named] = $this->kit->vehicles($user, $arguments);
        $state = $arguments->string('state') ?? 'current';
        if (!in_array($state, self::STATES, true)) {
            throw new ToolError('state must be one of: ' . implode(', ', self::STATES) . '.');
        }

        $rows = [];
        $totals = [];
        $count = 0;
        $withModifications = [];
        foreach ($vehicles as $vehicle) {
            $costs = $this->kit->canSeeCosts($user, $vehicle);
            $currency = $this->kit->currency($user, $vehicle);
            $listed = array_values(array_filter(
                $this->modifications->list($vehicle),
                static fn (Modification $m): bool => match ($state) {
                    'current' => !$m->isReverted(),
                    'reverted' => $m->isReverted(),
                    default => true,
                },
            ));
            usort($listed, static fn (Modification $a, Modification $b): int
                => $b->data->doneOn <=> $a->data->doneOn ?: $b->id <=> $a->id);
            foreach ($listed as $modification) {
                $count++;
                if ($costs) {
                    $totals[$currency] = ($totals[$currency] ?? Money::zero($currency))
                        ->add(Money::of($modification->data->cost, $currency));
                }
                if (count($rows) < ToolKit::LIST_CAP) {
                    $rows[] = $this->row($user, $vehicle, $modification, $costs, $currency);
                }
                $withModifications[$vehicle->id] = $vehicle->id;
            }
        }

        $build = [];
        foreach ($totals as $money) {
            $build[] = $this->kit->money($money);
        }
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;

        return new ToolResult(
            [
                'modifications' => $count,
                'rows' => $rows,
                'truncated' => $count > count($rows),
                ...($build === [] ? [] : [
                    'build_total' => $build,
                    'note' => 'build_total is what the modifications cost: it is not a running cost and is in no cost '
                        . 'per distance.',
                ]),
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.modifications'),
                $this->kit->t('modification.state.' . $state),
                $this->kit->vehiclesLabel($vehicles, $named),
            ]),
            array_map(static fn (array $money): string => $money['display'], $build),
            $one === null ? null : $this->kit->link('/vehicles/' . $one->id . '/parts'),
            $named ? array_map(static fn (Vehicle $v): int => $v->id, $vehicles) : array_values($withModifications),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user, Vehicle $vehicle, Modification $modification, bool $costs, string $currency): array
    {
        $data = $modification->data;
        $fitted = [];
        $removed = [];
        $made = $this->parts->byRecord($vehicle, PartService::MODIFICATION, $modification->id);
        foreach ($made as ['part' => $part, 'fitting' => $fitting]) {
            if ($fitting->fittedModificationId === $modification->id) {
                $fitted[] = $part->label() . ($part->data->partNumber === null ? '' : ' (' . $part->data->partNumber . ')');
            }
            if ($fitting->removedModificationId === $modification->id) {
                $removed[] = $part->label() . ' · ' . $this->kit->t('part.outcome.' . ($fitting->outcome->value ?? 'kept'));
            }
        }

        return [
            'vehicle' => $this->kit->vehicleRef($vehicle),
            'title' => $data->title,
            'category' => $this->kit->t($data->category->labelKey()),
            'done_on' => $data->doneOn->format('Y-m-d'),
            'done_on_display' => $this->kit->format->date($data->doneOn),
            'odometer' => $this->kit->distance($data->odometerKm),
            'fitted_by' => $data->fittedBy,
            'notes' => $data->notes,
            'declared_to_insurer' => $data->declaredOn !== null,
            'declared_on' => $data->declaredOn?->format('Y-m-d'),
            'declared_on_display' => $data->declaredOn === null ? null : $this->kit->format->date($data->declaredOn),
            'reverted' => $modification->isReverted(),
            'reverted_on' => $modification->revertedOn?->format('Y-m-d'),
            'parts_fitted' => $fitted,
            'parts_removed' => $removed,
            ...($costs ? ['cost' => $this->kit->money(Money::of($data->cost, $currency))] : []),
        ];
    }
}
