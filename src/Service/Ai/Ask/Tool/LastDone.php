<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;

/**
 * `last_done(vehicle, category or schedule)`: when a job was last done,
 * from the schedules (§7.4) and the maintenance records (spec.md §7.26),
 * with when a matching schedule is next due.
 */
final readonly class LastDone implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private ReminderSettingsStore $reminderSettings,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'last_done';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'When a job was last done on a vehicle (date and odometer), by category (e.g. oil) or by a '
            . 'maintenance schedule\'s id or title, and when the schedule is next due.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                    'category' => ['type' => 'string', 'enum' => self::categories()],
                    'schedule' => ['type' => 'string', 'description' => 'A schedule id, or words of its title.'],
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
        $schedule = $arguments->string('schedule');
        if ($category === null && $schedule === null) {
            throw new ToolError('Give a category or a schedule.');
        }

        $states = array_values(array_filter(
            $this->states($user, $vehicle),
            static fn (ScheduleState $s): bool => self::matches($s, $category, $schedule),
        ));
        $records = $category === null ? [] : $this->maintenance->history($vehicle)->newestFirst($category);
        if ($schedule !== null && $category === null && $states === []) {
            $records = array_values(array_filter(
                $this->maintenance->history($vehicle)->newestFirst(),
                static fn (MaintenanceEntry $e): bool => str_contains(mb_strtolower($e->data->title), mb_strtolower($schedule)),
            ));
        }
        $last = $records[0] ?? null;

        $figures = [];
        if ($last !== null) {
            $figures[] = $this->kit->format->date($last->data->performedOn);
            if ($last->data->odometerKm !== null) {
                $figures[] = $this->kit->format->distance($last->data->odometerKm);
            }
        }

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'category' => $category?->value,
                'last_record' => $last === null ? null : [
                    'id' => $last->id,
                    'title' => $last->data->title,
                    'category' => $last->data->category->value,
                    'date' => $last->data->performedOn->format('Y-m-d'),
                    'date_display' => $this->kit->format->date($last->data->performedOn),
                    'odometer' => $this->kit->distance($last->data->odometerKm),
                ],
                'schedules' => array_map(fn (ScheduleState $s): array => $this->schedule($s), $states),
                'note' => $last === null && $states === [] ? 'No record or schedule matches.' : null,
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.last_done'),
                $vehicle->name(),
                $category === null ? null : $this->kit->t('maintenance.category.' . $category->value),
                $schedule === null ? null : '"' . $schedule . '"',
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
     * @return list<ScheduleState>
     */
    private function states(User $user, Vehicle $vehicle): array
    {
        // The owner's lead times, as the vehicle page and reminders use.
        $lead = $this->reminderSettings->reminderPreferences($vehicle->userId);

        return $this->schedules->states(
            $vehicle,
            $this->kit->today($user),
            $this->odometer->history($vehicle),
            $lead->scheduleDays,
            $lead->scheduleKm,
        );
    }

    private static function matches(ScheduleState $state, ?MaintenanceCategory $category, ?string $schedule): bool
    {
        $data = $state->schedule->data;
        if ($schedule !== null) {
            if (ctype_digit($schedule)) {
                return $state->schedule->id === (int) $schedule;
            }

            return str_contains(mb_strtolower($data->title), mb_strtolower($schedule));
        }

        return $data->category === $category;
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(ScheduleState $state): array
    {
        $schedule = $state->schedule;
        $due = $state->due;

        return [
            'id' => $schedule->id,
            'title' => $schedule->data->title,
            'category' => $schedule->data->category->value,
            'interval_km' => $this->kit->distance($schedule->data->intervalKm),
            'interval_months' => $schedule->data->intervalMonths,
            'last_done_on' => $schedule->lastDone->on?->format('Y-m-d'),
            'last_done_on_display' => $schedule->lastDone->on === null ? null : $this->kit->format->date($schedule->lastDone->on),
            'last_done_odometer' => $this->kit->distance($schedule->lastDone->km),
            'next_due_on' => $due->dueOn?->format('Y-m-d') ?? $schedule->nextDue->on?->format('Y-m-d'),
            'next_due_on_display' => ($due->dueOn ?? $schedule->nextDue->on) === null
                ? null
                : $this->kit->format->date($due->dueOn ?? $schedule->nextDue->on),
            'next_due_odometer' => $this->kit->distance($schedule->nextDue->km),
            'status' => $due->status->value,
            'due_date_estimated' => $due->projected,
        ];
    }

    /**
     * @return list<string>
     */
    private static function categories(): array
    {
        return array_map(static fn (MaintenanceCategory $c): string => $c->value, MaintenanceCategory::cases());
    }
}
