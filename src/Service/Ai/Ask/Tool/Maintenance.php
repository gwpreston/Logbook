<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\User\User;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Money\Money;

/**
 * `maintenance(vehicle, category?, text?, period?, limit?)`: service
 * records, newest first (spec.md §7.26). An amount shows only where the
 * user may see it, as on the maintenance page.
 */
final readonly class Maintenance implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private MaintenanceService $maintenance,
        private EntryAccess $entries,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'maintenance';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'A vehicle\'s maintenance records (services, oil changes, repairs, ...), newest first. '
            . 'Filter by category, by words in the title, vendor or notes, and by period.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                    'category' => ['type' => 'string', 'enum' => self::categories()],
                    'text' => ['type' => 'string', 'description' => 'Words to look for, e.g. "oil" or "brake pads".'],
                    ...AskPeriod::SCHEMA,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ToolKit::LIST_CAP],
                ],
                'required' => ['vehicle'],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Maintenance);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        $category = MaintenanceCategory::tryFrom($arguments->choice('category', self::categories()) ?? '');
        $text = $arguments->string('text');
        $period = $this->kit->period($user, $arguments, 'all_time');
        $limit = $arguments->int('limit', 1, ToolKit::LIST_CAP) ?? 10;
        $records = array_values(array_filter(
            $this->maintenance->history($vehicle)->search($category, $text),
            static fn (MaintenanceEntry $entry): bool => $period->contains($entry->data->performedOn),
        ));
        $currency = $this->kit->currency($user, $vehicle);
        $shown = array_slice($records, 0, $limit);
        $rows = array_map(function (MaintenanceEntry $entry) use ($user, $vehicle, $currency): array {
            $data = $entry->data;

            return [
                'id' => $entry->id,
                'date' => $data->performedOn->format('Y-m-d'),
                'date_display' => $this->kit->format->date($data->performedOn),
                'category' => $data->category->value,
                'title' => $data->title,
                'odometer' => $this->kit->distance($data->odometerKm),
                'vendor' => $data->vendor,
                'notes' => $data->description,
                ...($this->entries->canSeeAmount($user, $vehicle, $entry->createdBy)
                    ? ['cost' => $this->kit->money(Money::of($data->cost, $currency))]
                    : []),
            ];
        }, $shown);

        $figures = array_map(fn (MaintenanceEntry $e): string => $this->kit->format->date($e->data->performedOn)
            . ' · ' . $e->data->title, array_slice($shown, 0, 3));

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'period' => $period->toArray(),
                'total_count' => count($records),
                'records' => $rows,
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.maintenance'),
                $vehicle->name(),
                $category === null ? null : $this->kit->t('maintenance.category.' . $category->value),
                $text === null ? null : '"' . $text . '"',
                $period->preset === 'all_time' ? null : $this->kit->periodLabel($period),
            ]),
            $figures,
            $this->kit->link(
                '/vehicles/' . $vehicle->id . '/maintenance',
                $category === null ? [] : ['category' => $category->value],
            ),
            [$vehicle->id],
        );
    }

    /**
     * @return list<string>
     */
    private static function categories(): array
    {
        return array_map(static fn (MaintenanceCategory $c): string => $c->value, MaintenanceCategory::cases());
    }
}
