<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\PartWarranty;
use Logbook\Support\Money\Money;

/**
 * `parts(vehicles?, status?)` (spec.md §7.26, §7.39, Phase 50.2): the parts on the user's vehicles, with
 * their warranty as the page words it, their distance on the vehicle and their fittings. Fitted ones by
 * default. A price needs `ViewCosts`.
 */
final readonly class Parts implements AskTool
{
    private const array STATUSES = ['fitted', 'stored', 'gone', 'all'];

    public function __construct(
        private ToolKit $kit,
        private PartService $parts,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'parts';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Parts on the user\'s vehicles: name, brand, part number, supplier, status (fitted, stored in the garage, '
            . 'gone: sold, returned or scrapped), condition, when it was bought, its warranty (in warranty until a '
            . 'date or mileage, or ended), the distance it has covered on the vehicle and each time it was fitted and '
            . 'removed. Fitted ones unless a status is given. The price is only given where the user may see costs.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Vehicle ids; all when left out.',
                    ],
                    'status' => ['type' => 'string', 'enum' => self::STATUSES],
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
        $status = $arguments->string('status') ?? 'fitted';
        if (!in_array($status, self::STATUSES, true)) {
            throw new ToolError('status must be one of: ' . implode(', ', self::STATUSES) . '.');
        }
        $statuses = $status === 'all' ? [] : [PartStatus::from($status)];

        $rows = [];
        $count = 0;
        $withParts = [];
        foreach ($vehicles as $vehicle) {
            $listed = $this->parts->list($vehicle, $statuses);
            if ($listed === []) {
                continue;
            }
            $fittings = $this->parts->fittingsFor($listed);
            foreach ($listed as $part) {
                $count++;
                if (count($rows) < ToolKit::LIST_CAP) {
                    $rows[] = $this->row($user, $vehicle, $part, $fittings[$part->id] ?? []);
                }
                $withParts[$vehicle->id] = $vehicle->id;
            }
        }
        $one = $named && count($vehicles) === 1 ? $vehicles[0] : null;

        return new ToolResult(
            [
                'parts' => $count,
                'rows' => $rows,
                'truncated' => $count > count($rows),
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.parts'),
                $this->kit->t($status === 'all' ? 'part.filter.all' : 'part.filter.' . $status),
                $this->kit->vehiclesLabel($vehicles, $named),
            ]),
            [(string) $count],
            $one === null
                ? null
                : $this->kit->link('/vehicles/' . $one->id . '/parts', $status === 'fitted' ? [] : ['status' => $status]),
            $named ? array_map(static fn (Vehicle $v): int => $v->id, $vehicles) : array_values($withParts),
        );
    }

    /**
     * @param list<PartFitting> $fittings oldest first
     * @return array<string, mixed>
     */
    private function row(User $user, Vehicle $vehicle, Part $part, array $fittings): array
    {
        $data = $part->data;
        $zone = $user->preferences->timeZone();
        $warranty = $this->parts->warranty($vehicle, $part, $fittings, $zone);
        $distance = $this->parts->distance($vehicle, $fittings, $zone);

        return [
            'vehicle' => $this->kit->vehicleRef($vehicle),
            'name' => $part->label(),
            'part_number' => $data->partNumber,
            'serial_number' => $data->serialNumber,
            'supplier' => $data->supplier,
            'status' => $this->kit->t('part.status.' . $part->status->value),
            'condition' => $this->kit->t('part.condition.' . $data->condition->value),
            'original_with_the_car' => $data->origin->value === 'original',
            'bought_on' => $data->boughtOn?->format('Y-m-d'),
            'warranty' => $this->warranty($data->hasWarranty(), $warranty),
            'distance_on_vehicle' => ($this->kit->distance($distance->km) ?? ['km' => null, 'display' => null])
                + ['at_least' => $distance->atLeast],
            'fittings' => array_map(fn (PartFitting $f): array => [
                'fitted_on' => $f->fittedOn->format('Y-m-d'),
                'fitted_odometer' => $this->kit->distance($f->fittedOdometerKm),
                'removed_on' => $f->removedOn?->format('Y-m-d'),
                'removed_odometer' => $this->kit->distance($f->removedOdometerKm),
                'outcome' => $f->outcome === null ? null : $this->kit->t('part.outcome.' . $f->outcome->value),
            ], $fittings),
            ...($this->kit->canSeeCosts($user, $vehicle) && $data->price !== null
                ? ['price' => $this->kit->money(Money::of($data->price, $this->kit->currency($user, $vehicle)))]
                : []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function warranty(bool $has, ?PartWarranty $warranty): ?array
    {
        if (!$has || $warranty === null) {
            return null;
        }
        $until = $warranty->until === null ? null : $this->kit->format->date($warranty->until);
        $untilKm = $warranty->untilKm === null ? null : $this->kit->format->distance($warranty->untilKm);
        $limits = array_values(array_filter([$until, $untilKm]));

        return [
            'active' => $warranty->active,
            'until' => $warranty->until?->format('Y-m-d'),
            'until_odometer' => $this->kit->distance($warranty->untilKm),
            'display' => match (true) {
                !$warranty->active => $this->kit->t('part.warranty.ended'),
                $limits === [] => $this->kit->t('part.warranty.active'),
                default => $this->kit->t('ask.result.in_warranty', ['limits' => implode(' or ', $limits)]),
            },
        ];
    }
}
