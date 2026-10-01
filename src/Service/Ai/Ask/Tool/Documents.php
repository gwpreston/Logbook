<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Money\Money;

/**
 * `documents(vehicle?, type?)`: insurance, MOT, registration and other
 * documents with their expiry and status, current ones first, and a first
 * MOT still to come (spec.md §7.26, compliance §7.5). Statuses use the
 * vehicle owner's document lead time, as the vehicle's reminders do. A
 * cost shows only where the user may see it.
 */
final readonly class Documents implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private ComplianceService $compliance,
        private FirstInspection $firstInspection,
        private ReminderSettingsStore $reminderSettings,
        private EntryAccess $entries,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'documents';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'A vehicle\'s documents (insurance, MOT/inspection, registration, pollution certificate, other) '
            . 'with start and expiry dates, status (valid, expiring, expired, ...) and provider. Leave vehicle out '
            . 'for every active vehicle.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                    'type' => ['type' => 'string', 'enum' => self::types()],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Compliance);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $one = $this->kit->optionalVehicle($user, $arguments);
        $vehicles = $one === null ? $this->kit->fleet($user, false) : [$one];
        $type = ComplianceType::tryFrom($arguments->choice('type', self::types()) ?? '');
        $today = $this->kit->today($user);

        $rows = [];
        $firsts = [];
        $figures = [];
        foreach ($vehicles as $vehicle) {
            $lead = $this->reminderSettings->reminderPreferences($vehicle->userId)->documentDays;
            $currency = $this->kit->currency($user, $vehicle);
            foreach ($this->compliance->states($vehicle, $today, $lead) as $state) {
                if ($type !== null && $state->document->data->type !== $type) {
                    continue;
                }
                $rows[] = $this->row($user, $vehicle, $currency, $state);
                if ($state->status->isCurrent() && count($figures) < 3) {
                    $figures[] = $this->label($state) . ($state->document->data->expiryOn === null
                        ? ''
                        : ' · ' . $this->kit->format->date($state->document->data->expiryOn));
                }
            }
            if ($type === null || $type === ComplianceType::Inspection) {
                $due = $this->firstInspection->due($vehicle, $today, $lead);
                if ($due !== null) {
                    $firsts[] = [
                        'vehicle' => $this->kit->vehicleRef($vehicle),
                        'due_on' => $due->dueOn->format('Y-m-d'),
                        'due_on_display' => $this->kit->format->date($due->dueOn),
                        'days_left' => $due->daysLeft,
                        'status' => $due->status->value,
                    ];
                }
            }
        }

        return new ToolResult(
            [
                'today' => $today->format('Y-m-d'),
                'total_count' => count($rows),
                'documents' => array_slice($rows, 0, ToolKit::LIST_CAP),
                'first_mot_due' => $firsts,
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.documents'),
                $one === null ? $this->kit->t('ask.source.all_vehicles') : $one->name(),
                $type === null ? null : $this->kit->t('compliance.type.' . $type->value),
            ]),
            $figures,
            $one === null ? '/garage' : '/vehicles/' . $one->id . '/documents',
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user, Vehicle $vehicle, string $currency, DocumentState $state): array
    {
        $data = $state->document->data;

        return [
            'vehicle' => $this->kit->vehicleRef($vehicle),
            'type' => $data->type->value,
            'name' => $this->label($state),
            'status' => $state->status->value,
            'current' => $state->status->isCurrent(),
            'start_on' => $data->startOn?->format('Y-m-d'),
            'expiry_on' => $data->expiryOn?->format('Y-m-d'),
            'expiry_display' => $data->expiryOn === null ? null : $this->kit->format->date($data->expiryOn),
            'days_left' => $state->daysLeft,
            'provider' => $data->provider,
            'reference' => $data->reference,
            'notes' => $data->notes,
            ...($this->entries->canSeeAmount($user, $vehicle, $state->document->createdBy)
                ? ['cost' => $this->kit->money(Money::of($data->cost, $currency))]
                : []),
        ];
    }

    private function label(DocumentState $state): string
    {
        $data = $state->document->data;

        return $data->title ?? $this->kit->t('compliance.type.' . $data->type->value);
    }

    /**
     * @return list<string>
     */
    private static function types(): array
    {
        return array_map(static fn (ComplianceType $t): string => $t->value, ComplianceType::cases());
    }
}
