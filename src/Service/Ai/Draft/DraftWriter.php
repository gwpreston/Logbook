<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use Logbook\Domain\Incident\DamageArea;
use Logbook\Service\Api\ApiIncidents;
use Logbook\Service\Api\ApiIssues;
use Logbook\Service\Incident\IncidentForm;
use DateTimeImmutable;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Api\ApiWriter;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueForm;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Station\StationService;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Validation\ValidationErrors;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes a draft exactly as the API writes it (spec.md §7.26 *Drafting
 * entries*): the API's input adapter, the form's parser, the form's
 * service. A draft tool calls it inside a transaction that is rolled back,
 * to see what the entry would be; *Add* calls it for real. What it returns
 * is the card (each field as Logbook formatted it, in the user's units and
 * language), the create form's values for *Edit*, and the warnings.
 *
 * Access and modules are checked here, as the API's routes check them:
 * a vehicle the user may not log on is not found.
 */
final readonly class DraftWriter
{
    public function __construct(
        private ApiWriter $writer,
        private VehicleService $vehicles,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private ScheduleService $schedules,
        private DisplayFormatter $format,
        private TranslatorInterface $translator,
        private ApiIncidents $incidents,
        private ApiIssues $issues,
        private StationService $stations,
    ) {
    }

    /**
     * The vehicle, if the user may add this kind of entry to it now.
     *
     * @throws DraftRefused
     */
    public function vehicle(User $user, DraftKind $kind, int $vehicleId): Vehicle
    {
        $feature = $kind->feature();
        if ($feature !== null && !$this->features->isEnabled($feature)) {
            throw new DraftRefused('ask.draft.refused.module');
        }
        try {
            $vehicle = $this->vehicles->get($user, $vehicleId);
        } catch (VehicleNotFound) {
            throw new DraftRefused('ask.draft.refused.vehicle');
        }
        if (!$this->access->can($user, $kind->ability(), $vehicle)) {
            throw new DraftRefused('ask.draft.refused.vehicle');
        }
        if ($vehicle->isArchived()) {
            throw new DraftRefused('ask.draft.refused.archived');
        }

        return $vehicle;
    }

    /**
     * @param array<string, mixed> $input the API body
     * @throws DraftInvalid with the form's errors
     * @throws DraftRefused
     */
    public function write(User $user, Vehicle $vehicle, DraftKind $kind, array $input): DraftWritten
    {
        try {
            return match ($kind) {
                DraftKind::Fuel => $this->fuel($user, $vehicle, $input),
                DraftKind::Odometer => $this->reading($user, $vehicle, $input),
                DraftKind::Maintenance => $this->maintenance($user, $vehicle, $input),
                DraftKind::Document => $this->document($user, $vehicle, $input),
                DraftKind::Expense => $this->expense($user, $vehicle, $input),
                DraftKind::TyreCheck => $this->treadCheck($user, $vehicle, $input),
                DraftKind::Reminder => $this->reminder($user, $vehicle, $input),
                DraftKind::Incident => $this->incident($user, $vehicle, $input),
                DraftKind::Issue => $this->issue($user, $vehicle, $input),
            };
        } catch (ApiProblem $problem) {
            if ($problem->validation !== null) {
                throw new DraftInvalid($problem->validation);
            }
            if ($problem->problemCode === 'vehicle_archived') {
                throw new DraftRefused('ask.draft.refused.archived');
            }
            throw $problem;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fuel(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        // Phase 30.1 (spec.md §7.33, #134): whether the named station is new,
        // asked before the write creates it, so the card can say so.
        $named = is_string($input['station'] ?? null) ? $input['station'] : '';
        $newStation = $named !== '' && $this->stations->enabled() && $this->stations->existing($named) === null;
        $result = $this->writer->logFuel($user, $vehicle, $input);
        $entry = $result['entry'];
        $data = $entry->data;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $kind = $data->fuel->kind();
        $given = static fn (string $field): bool => ($input[$field] ?? null) !== null;
        $derived = match (true) {
            !$given('total_cost') => 'total',
            !$given('price_per_unit') => 'price',
            !$given('volume') => 'volume',
            default => null,
        };
        $volume = $this->format->quantity($data->volume, $kind, 2, 2);
        $price = $this->format->unitPrice($data->pricePerUnit, $currency, $kind, true);
        $total = $this->format->money(Money::of($data->totalCost, $currency));
        $fuel = $data->grade === null
            ? $this->t('fuel.fuel.' . $data->fuel->value)
            : $this->t($data->grade->shortLabelKey());

        $fields = [
            self::field('when', $this->format->dateTime($data->filledAt)),
            self::field('odometer', $this->format->distance($data->odometerKm)),
            self::field('fuel', $fuel),
            self::field('volume', $volume, $derived === 'volume'),
            self::field('price', $price, $derived === 'price'),
            self::field('total', $total, $derived === 'total'),
        ];
        if ($data->isPartial) {
            $fields[] = self::field('partial', $this->t('ask.draft.yes'));
        }
        if ($data->isMissedPrevious) {
            $fields[] = self::field('missed_previous', $this->t('ask.draft.yes'));
        }
        if ($data->station !== null) {
            $fields[] = self::field('station', $newStation && $data->stationId !== null
                ? $this->t('stations.combo.new', ['name' => $data->station])
                : $data->station);
        }

        return $this->written(
            DraftKind::Fuel,
            $entry->id,
            $entry->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.fuel', ['volume' => $volume, 'fuel' => $fuel, 'price' => $price, 'total' => $total]),
            $fields,
            $derived === null ? [] : [$this->t('ask.draft.derived.' . $derived)],
            FuelEntryForm::values($entry, $user->preferences),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reading(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logReading($user, $vehicle, $input);
        $reading = $result['reading'];
        $odometer = $this->format->distance($reading->readingKm);

        return $this->written(
            DraftKind::Odometer,
            $reading->id,
            $reading->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.odometer', ['odometer' => $odometer]),
            [
                self::field('when', $this->format->dateTime($reading->recordedAt)),
                self::field('odometer', $odometer),
                ...($reading->note === null ? [] : [self::field('note', $reading->note)]),
            ],
            [],
            OdometerReadingForm::values($reading, $user->preferences),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function maintenance(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logMaintenance($user, $vehicle, $input);
        $entry = $result['entry'];
        $data = $entry->data;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $fields = [
            self::field('date', $this->format->date($data->performedOn)),
            self::field('category', $this->t('maintenance.category.' . $data->category->value)),
            self::field('title', $data->title),
            self::field('cost', $this->format->money(Money::of($data->cost, $currency))),
        ];
        if ($data->odometerKm !== null) {
            $fields[] = self::field('odometer', $this->format->distance($data->odometerKm));
        }
        if ($data->vendor !== null) {
            $fields[] = self::field('vendor', $data->vendor);
        }
        // Schedules it may complete are suggested, never ticked (spec.md §7.26).
        $notes = [];
        foreach ($this->schedules->list($vehicle) as $schedule) {
            if ($schedule->data->category === $data->category) {
                $notes[] = $this->t('ask.draft.schedule_hint', ['schedule' => $schedule->data->title]);
            }
        }

        return $this->written(
            DraftKind::Maintenance,
            $entry->id,
            $entry->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.maintenance', ['title' => $data->title]),
            $fields,
            $notes,
            MaintenanceEntryForm::values($entry, $user->preferences),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function document(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logDocument($user, $vehicle, $input);
        $document = $result['entry'];
        $data = $document->data;
        $type = $this->t('compliance.type.' . $data->type->value);
        $fields = [self::field('type', $type)];
        foreach (
            [
                'title' => $data->title,
                'provider' => $data->provider,
                'reference' => $data->reference,
                'start_on' => $data->startOn === null ? null : $this->format->date($data->startOn),
                'expiry_on' => $data->expiryOn === null ? null : $this->format->date($data->expiryOn),
            ] as $label => $value
        ) {
            if ($value !== null) {
                $fields[] = self::field($label, $value);
            }
        }
        $fields[] = self::field(
            'cost',
            $this->format->money(Money::of($data->cost, $this->vehicles->currencyFor($user, $vehicle))),
        );

        return $this->written(
            DraftKind::Document,
            $document->id,
            $document->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.document', [
                'type' => $data->title ?? $type,
                'expiry' => $data->expiryOn === null ? '' : $this->format->date($data->expiryOn),
            ]),
            $fields,
            [],
            ComplianceDocumentForm::values($document, $user->preferences),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function expense(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logExpense($user, $vehicle, $input);
        $entry = $result['entry'];
        $data = $entry->data;
        $amount = $this->format->money(Money::of($data->amount, $this->vehicles->currencyFor($user, $vehicle)));
        $category = $this->t('expense.category.' . $data->category->value);

        return $this->written(
            DraftKind::Expense,
            $entry->id,
            $entry->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.expense', ['category' => $category, 'amount' => $amount]),
            [
                self::field('date', $this->format->date($data->spentOn)),
                self::field('category', $category),
                self::field('amount', $amount),
                ...($data->note === null ? [] : [self::field('note', $data->note)]),
            ],
            [],
            ExpenseEntryForm::values($entry),
        );
    }

    /**
     * An incident (Phase 27.1): its date, type, damage and claim on the card.
     *
     * @param array<string, mixed> $input
     */
    private function incident(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->incidents->logIncident($user, $vehicle, $input);
        $incident = $result['incident'];
        $data = $incident->data;
        $type = $this->t($data->type->labelKey());
        $fields = [
            self::field('date', $this->format->date($data->occurredOn)),
            self::field('type', $type),
        ];
        if ($data->damageAreas !== []) {
            $fields[] = self::field('damage', implode(', ', array_map(
                fn (DamageArea $area): string => $this->t($area->labelKey()),
                $data->damageAreas,
            )));
        }
        $fields[] = self::field('fault', $this->t($data->fault->labelKey()));
        if ($data->claim->status->isClaim()) {
            $fields[] = self::field('claim', $this->t($data->claim->status->labelKey()));
        }
        if ($data->claim->insurer !== null) {
            $fields[] = self::field('insurer', $data->claim->insurer);
        }

        return $this->written(
            DraftKind::Incident,
            $incident->id,
            $incident->updatedAt,
            $result['duplicate'],
            [],
            $this->t('ask.draft.summary.incident', ['type' => $type, 'date' => $this->format->date($data->occurredOn)]),
            $fields,
            [],
            IncidentForm::flatValues(IncidentForm::values($incident, $result['odometerKm'], $user->preferences)),
        );
    }

    /**
     * An issue (Phase 40.2): the user's words, never a cause.
     *
     * @param array<string, mixed> $input
     */
    private function issue(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->issues->logIssue($user, $vehicle, $input);
        $issue = $result['issue'];
        $data = $issue->data;
        $fields = [
            self::field('date', $this->format->date($data->noticedOn)),
            self::field('title', $data->title),
        ];
        if ($data->odometerKm !== null) {
            $fields[] = self::field('odometer', $this->format->distance($data->odometerKm));
        }
        $fields[] = self::field('status', $this->t($issue->status()->labelKey()));
        if ($data->lookAgainOn !== null) {
            $fields[] = self::field('look_again', $this->format->date($data->lookAgainOn));
        }
        if ($data->affectsSafety) {
            $fields[] = self::field('affects_safety', $this->t('ask.draft.yes'));
        }

        return $this->written(
            DraftKind::Issue,
            $issue->id,
            $issue->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.issue', ['title' => $data->title, 'date' => $this->format->date($data->noticedOn)]),
            $fields,
            [],
            IssueForm::values($issue, $user->preferences),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function treadCheck(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logTreadCheck($user, $vehicle, $input);
        $check = $result['check'];
        $preferences = $user->preferences;
        $fields = [self::field('date', $this->format->date($check->data->doneOn))];
        if ($check->data->odometerKm !== null) {
            $fields[] = self::field('odometer', $this->format->distance($check->data->odometerKm));
        }
        $values = TyreChangeForm::values($check, $preferences);
        unset($values['link']);
        $depths = [];
        foreach ($check->lines as $line) {
            if ($line->treadMm === null) {
                continue;
            }
            $depth = $this->format->depth($line->treadMm);
            $position = $line->position === null ? '' : $this->t('tyre.position.' . $line->position->value);
            $fields[] = ['label' => $position, 'value' => $depth, 'derived' => false, 'raw' => true];
            $depths[] = $position . ' ' . $depth;
            $values['tread_' . $line->tyreId] = $preferences->depthUnit->toInput($line->treadMm);
        }

        return $this->written(
            DraftKind::TyreCheck,
            $check->id,
            $check->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.tyre_check', ['depths' => implode(', ', $depths)]),
            $fields,
            [],
            $values,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reminder(User $user, Vehicle $vehicle, array $input): DraftWritten
    {
        $result = $this->writer->logReminder($user, $vehicle, $input);
        $reminder = $result['entry']->reminder;
        $due = $reminder->dueOn === null ? '' : $this->format->date($reminder->dueOn);

        return $this->written(
            DraftKind::Reminder,
            $reminder->id,
            $reminder->updatedAt,
            $result['duplicate'],
            $result['warnings'],
            $this->t('ask.draft.summary.reminder', ['title' => $reminder->title, 'due' => $due]),
            [
                self::field('title', $reminder->title),
                self::field('due_on', $due),
                self::field('lead_time_days', $this->t('ask.draft.days', ['count' => $reminder->leadTimeDays])),
                ...($reminder->notes === null ? [] : [self::field('note', $reminder->notes)]),
            ],
            [],
            ManualReminderForm::values($reminder, $user->preferences),
        );
    }

    /**
     * @param list<array{code: string, detail: string}> $warnings
     * @param list<array{label: string, value: string, derived: bool, raw: bool}> $fields
     *        names under ask.draft.field, or raw labels
     * @param list<string> $notes
     * @param array<string, string> $formValues
     */
    private function written(
        DraftKind $kind,
        int $entryId,
        DateTimeImmutable $updatedAt,
        bool $duplicate,
        array $warnings,
        string $summary,
        array $fields,
        array $notes,
        array $formValues,
    ): DraftWritten {
        $card = [
            'title' => $this->t($kind->labelKey()),
            'summary' => $summary,
            'fields' => array_map(fn (array $f): array => [
                'label' => $f['raw'] ? $f['label'] : $this->t('ask.draft.field.' . $f['label']),
                'value' => $f['value'],
                'derived' => $f['derived'],
            ], $fields),
            'notes' => $notes,
            'warnings' => array_map(
                fn (array $w): string => $this->t('ask.draft.warning.' . $w['code']),
                $warnings,
            ),
        ];

        return new DraftWritten($entryId, $updatedAt, $duplicate, array_column($warnings, 'code'), $card, $formValues);
    }

    /**
     * @return array{label: string, value: string, derived: bool, raw: bool}
     */
    private static function field(string $name, string $value, bool $derived = false): array
    {
        return ['label' => $name, 'value' => $value, 'derived' => $derived, 'raw' => false];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function t(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
