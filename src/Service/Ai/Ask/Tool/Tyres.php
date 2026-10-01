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
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSetGroup;
use Logbook\Service\Tyre\TyreVerdict;
use Logbook\Service\Tyre\TyreView;
use Logbook\Support\Number\Decimal;

/**
 * `tyres(vehicle)`: the fitted tyres by position and the stored sets, with
 * distance, age, the latest tread depth and the wear estimate, and the
 * vehicle's tyre verdict (spec.md §7.26, tyres §7.17). Cost per distance
 * only with the vehicle's costs.
 */
final readonly class Tyres implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private TyreService $tyres,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'tyres';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'A vehicle\'s tyres: those fitted (by position) and stored sets, with brand, size, season, distance '
            . 'covered, age, the last measured tread depth, the estimated distance and date until they are worn, '
            . 'and whether they are due for replacing.',
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
        return $this->features->isEnabled(Feature::Tyres);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        $overview = $this->tyres->overview($vehicle, $user);
        $costs = $this->kit->canSeeCosts($user, $vehicle);
        $currency = $this->kit->currency($user, $vehicle);

        $fitted = [];
        foreach ($overview->fitted as $position => $view) {
            if ($view !== null) {
                $fitted[] = ['position' => $position, 'position_label' => $this->kit->t('tyre.position.' . $position)]
                    + $this->tyre($view, $costs, $currency);
            }
        }
        $stored = array_map(fn (TyreSetGroup $group): array => [
            'set' => $group->set?->data->name,
            'stored_at' => $group->set?->data->storageLocation,
            'tyres' => array_map(fn (TyreView $view): array => $this->tyre($view, $costs, $currency), $group->tyres),
        ], $overview->stored);

        $verdict = $this->verdict($overview->verdict);
        $figures = [$this->kit->t('ask.result.tyre_status.' . $overview->verdict->status->value)];
        $soonest = $overview->soonestKmLeft();
        if ($soonest !== null) {
            $figures[] = $this->kit->format->distance($soonest);
        }

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'fitted' => $fitted,
                'stored' => $stored,
                'retired_count' => count($overview->retired),
                'soonest_distance_left' => $this->kit->distance($soonest),
                'verdict' => $verdict,
            ],
            $this->kit->source([$this->kit->t('ask.tool.tyres'), $vehicle->name()]),
            $figures,
            '/vehicles/' . $vehicle->id . '/tyres',
            [$vehicle->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tyre(TyreView $view, bool $costs, string $currency): array
    {
        $data = $view->tyre->data;
        $wear = $view->wear;

        return [
            'id' => $view->tyre->id,
            'brand' => $data->brand,
            'model' => $data->model,
            'size' => $data->size,
            'season' => $data->season?->value,
            'dot' => $data->dot?->code,
            'notes' => $data->notes,
            'distance' => $this->kit->distance($view->distance->km),
            'age' => $view->age === null ? null : ['years' => $view->age->years, 'months' => $view->age->months],
            'fitted_since' => $view->since?->format('Y-m-d'),
            'tread' => $wear->latest === null ? null : [
                'mm' => $wear->latest->treadMm,
                'display' => $this->kit->format->depth($wear->latest->treadMm),
                'measured_on' => $wear->latest->doneOn->format('Y-m-d'),
                'measured_on_display' => $this->kit->format->date($wear->latest->doneOn),
            ],
            'estimated_tread_now' => $wear->depthNowMm === null ? null : [
                'mm' => $wear->depthNowMm,
                'display' => $this->kit->format->depth($wear->depthNowMm),
            ],
            'replace_at' => $wear->replaceAtMm === null ? null : [
                'mm' => $wear->replaceAtMm,
                'display' => $this->kit->format->depth($wear->replaceAtMm),
            ],
            'distance_left' => $this->kit->distance($wear->kmLeft),
            'worn_out_by' => $wear->wearOutOn?->format('Y-m-d'),
            'worn_out_by_display' => $wear->wearOutOn === null ? null : $this->kit->format->date($wear->wearOutOn),
            'worn' => $wear->worn,
            'legal_flag' => $wear->legal?->value,
            'age_limit_on' => $view->ageLimitOn?->format('Y-m-d'),
            ...($costs && $view->costPerKm !== null ? ['cost_per_distance' => [
                'cost_per_km' => Decimal::round($view->costPerKm, 4),
                'display' => $this->kit->format->perDistance($view->costPerKm, $currency),
            ]] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(TyreVerdict $verdict): array
    {
        return [
            'status' => $verdict->status->value,
            'status_label' => $this->kit->t('ask.result.tyre_status.' . $verdict->status->value),
            'reason' => $verdict->reason,
            'due_on' => $verdict->dueOn?->format('Y-m-d'),
            'due_on_display' => $verdict->dueOn === null ? null : $this->kit->format->date($verdict->dueOn),
            'due_odometer' => $this->kit->distance($verdict->dueKm),
        ];
    }
}
