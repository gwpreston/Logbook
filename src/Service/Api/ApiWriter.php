<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreFormContexts;
use Logbook\Service\Tyre\TyreService;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Repository\TripRepository;
use Logbook\Service\Import\DuplicateKey;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Service\Station\StationService;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * The API's writes (spec.md §7.20): a fill-up, an odometer reading, a
 * trip (Phase 22), and a service record, document, expense, tread check
 * and manual reminder (Phase 26.3).
 * Each goes through its form's parser and its service, exactly as the
 * forms and the CSV import do, so validation, the derived amount, the
 * odometer reading, schedules, reminders and the economy check all apply.
 * An entry the CSV import would call a duplicate is not written again:
 * the existing one is returned, so a retry never doubles anything.
 */
final readonly class ApiWriter
{
    public function __construct(
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TyreChangeService $tyreChanges,
        private TyreService $tyres,
        private TyreFormContexts $tyreContexts,
        private ReminderService $reminders,
        private ReminderSettingsStore $reminderSettings,
        private FuelService $fuel,
        private OdometerService $odometer,
        private VehicleService $vehicles,
        private TripService $trips,
        private TripRepository $tripEntries,
        private SavedJourneyService $journeys,
        private ValidationProblem $validation,
        private ClockInterface $clock,
        private StationService $stations,
    ) {
    }

    /**
     * @param array<string, mixed> $body the decoded JSON body (JsonInput::decode)
     * @return array{entry: FuelEntry, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logFuel(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $now = $this->clock->now();
        $defaults = FuelEntryForm::defaults($vehicle, $now, $user->preferences, $this->fuel->entries($vehicle));
        $mapped = JsonInput::fuel($body, $user->preferences, $defaults['fuel'] ?? '', $now);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = FuelEntryForm::parse($mapped['input'], $mapped['preferences'], $this->vehicles->currencyFor($user, $vehicle));
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::FUEL_FIELDS));
        }
        if ($data->stationId !== null && $this->stations->enabled() && $this->stations->resolve($data->stationId) === null) {
            $errors = new ValidationErrors();
            $errors->add('station_id', 'api.validation.station_unknown');
            throw $this->validation->of($errors);
        }

        $existing = $this->existingFill($vehicle, $data);
        if ($existing !== null) {
            return ['entry' => $existing, 'duplicate' => true, 'warnings' => []];
        }

        $entry = $this->fuel->create($vehicle, $data);
        $warnings = self::odometerWarnings($this->fuel->odometerWarning($vehicle, $entry));
        if ($this->fuel->checks($this->fuel->history($vehicle))->isFlagged($entry->id)) {
            $warnings[] = [
                'code' => 'economy_check',
                'detail' => 'The economy of the tank this fill-up closes is far from this vehicle\'s usual; '
                    . 'check the volume and odometer.',
            ];
        }

        return ['entry' => $entry, 'duplicate' => false, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{reading: OdometerReading, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logReading(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $mapped = JsonInput::reading($body, $user->preferences, $this->clock->now());
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = OdometerReadingForm::parse($mapped['input'], $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::READING_FIELDS));
        }

        // Every reading counts, whatever wrote it: a fill-up's reading repeated is a duplicate too.
        $key = DuplicateKey::of($data);
        foreach ($this->odometer->history($vehicle)->readings as $reading) {
            if (DuplicateKey::of(new OdometerReadingData($reading->readingKm, $reading->recordedAt)) === $key) {
                return ['reading' => $reading, 'duplicate' => true, 'warnings' => []];
            }
        }

        $reading = $this->odometer->create($vehicle, $data);

        return [
            'reading' => $reading,
            'duplicate' => false,
            'warnings' => self::odometerWarnings($this->odometer->warningFor($vehicle, $reading->id)),
        ];
    }

    /**
     * A trip, in kilometres, the whole trip's distance (spec.md §7.22):
     * the key's user is its driver. A retry matches only their own trips
     * on the vehicle, by the import's key, so another driver's trip is
     * never returned (destinations are personal).
     *
     * @param array<string, mixed> $body
     * @return array{trip: Trip, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logTrip(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $mapped = JsonInput::trip(
            $body,
            $user->preferences,
            $today,
            fn (int $id): ?SavedJourney => $this->journeys->find($user, $id),
        );
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = TripForm::parse($mapped['input'], $mapped['preferences'], $today, wholeDistance: true);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::TRIP_FIELDS));
        }

        $key = DuplicateKey::of($data);
        foreach ($this->tripEntries->listForVehicle($vehicle->id, $user->id) as $trip) {
            if (DuplicateKey::of($trip->data) === $key) {
                return ['trip' => $trip, 'duplicate' => true, 'warnings' => []];
            }
        }

        $trip = $this->trips->create($vehicle, $data);
        $warnings = [];
        $driven = $this->trips->longerThanDriven($vehicle, $data, $zone);
        if ($driven !== null) {
            $warnings[] = [
                'code' => 'trip_longer_than_driven',
                'detail' => sprintf(
                    'The odometer readings around this day allow at most %s km; check the distance.',
                    Decimal::trim($driven),
                ),
            ];
        }

        return ['trip' => $trip, 'duplicate' => false, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{entry: MaintenanceEntry, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logMaintenance(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $zone = $user->preferences->timeZone();
        $mapped = JsonInput::maintenance($body, $user->preferences, LocalTime::today($this->clock, $zone));
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $scheduleIds = array_map(static fn (MaintenanceSchedule $s): int => $s->id, $this->schedules->list($vehicle));
        $data = MaintenanceEntryForm::parse($mapped['input'], $mapped['preferences'], $scheduleIds);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::MAINTENANCE_FIELDS));
        }

        $key = DuplicateKey::of($data);
        foreach ($this->maintenance->history($vehicle)->entries as $entry) {
            if (DuplicateKey::of($entry->data) === $key) {
                return ['entry' => $entry, 'duplicate' => true, 'warnings' => []];
            }
        }

        $entry = $this->maintenance->create($vehicle, $data, $zone);

        return [
            'entry' => $entry,
            'duplicate' => false,
            'warnings' => self::odometerWarnings($this->maintenance->odometerWarning($vehicle, $entry)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{entry: ComplianceDocument, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logDocument(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $mapped = JsonInput::document($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = ComplianceDocumentForm::parse($mapped['input'], $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::DOCUMENT_FIELDS));
        }

        $key = DuplicateKey::of($data);
        foreach ($this->compliance->list($vehicle) as $document) {
            if (DuplicateKey::of($document->data) === $key) {
                return ['entry' => $document, 'duplicate' => true, 'warnings' => []];
            }
        }

        $document = $this->compliance->create($vehicle, $data, $user->preferences->timeZone());

        return [
            'entry' => $document,
            'duplicate' => false,
            'warnings' => self::odometerWarnings($this->compliance->odometerWarning($vehicle, $document)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{entry: ExpenseEntry, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logExpense(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $mapped = JsonInput::expense($body, $user->preferences, LocalTime::today($this->clock, $user->preferences->timeZone()));
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = ExpenseEntryForm::parse($mapped['input'], $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::EXPENSE_FIELDS));
        }

        $key = DuplicateKey::of($data);
        foreach ($this->expenses->entries($vehicle) as $entry) {
            if (DuplicateKey::of($entry->data) === $key) {
                return ['entry' => $entry, 'duplicate' => true, 'warnings' => []];
            }
        }

        return ['entry' => $this->expenses->create($vehicle, $data), 'duplicate' => false, 'warnings' => []];
    }

    /**
     * *Check tread* (spec.md §7.17): a depth for each fitted position named.
     * A retry matches a check on the same date with the same depths.
     *
     * @param array<string, mixed> $body
     * @return array{check: TyreChange, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logTreadCheck(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $preferences = $user->preferences;
        $today = LocalTime::today($this->clock, $preferences->timeZone());
        $context = $this->tyreContexts->for($vehicle, $today, $today);
        $fitted = array_map(static fn (Tyre $tyre): int => $tyre->id, $context->fitted);
        $mapped = JsonInput::treadCheck($body, $preferences, $today, $fitted);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $parsed = TyreChangeForm::parse(TyreChangeKind::Check, $mapped['input'], $mapped['preferences'], $context);
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of(self::treadErrors($parsed, $fitted));
        }

        $key = self::treadKey($parsed->data->doneOn, $parsed->depths);
        foreach ($this->tyres->changes($vehicle) as $change) {
            if ($change->kind !== TyreChangeKind::Check) {
                continue;
            }
            $depths = [];
            foreach ($change->lines as $line) {
                $depths[$line->tyreId] = (string) $line->treadMm;
            }
            if (self::treadKey($change->data->doneOn, $depths) === $key) {
                return ['check' => $change, 'duplicate' => true, 'warnings' => []];
            }
        }

        try {
            $check = $this->tyreChanges->record($vehicle, $parsed, $preferences->timeZone(), $preferences->locale);
        } catch (TyreChangeRefused $refused) {
            $params = [];
            foreach ($refused->params as $name => $value) {
                $params[$name] = $value instanceof DateTimeImmutable ? $value->format('Y-m-d') : $value;
            }
            $errors = new ValidationErrors();
            $errors->add($refused->field, $refused->key, $params);
            throw $this->validation->of($errors);
        }
        $warnings = self::odometerWarnings($this->odometer->warningForEntry($vehicle, OdometerSource::Tyre, $check->id));
        foreach ($this->tyreChanges->deeperReadings($vehicle, $check) as $deeper) {
            $warnings[] = [
                'code' => 'tread_deeper',
                'detail' => sprintf(
                    'Tyre %d measured deeper than its last check (%s mm); check the depth.',
                    $deeper->tyre->id,
                    Decimal::trim($deeper->previous->treadMm),
                ),
            ];
        }

        return ['check' => $check, 'duplicate' => false, 'warnings' => $warnings];
    }

    /**
     * A manual reminder (spec.md §7.6). A retry matches an open manual
     * reminder on the vehicle with the same title and due date.
     *
     * @param array<string, mixed> $body
     * @return array{
     *     entry: ReminderEntry,
     *     today: DateTimeImmutable,
     *     duplicate: bool,
     *     warnings: list<array{code: string, detail: string}>,
     * }
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logReminder(User $user, Vehicle $vehicle, array $body): array
    {
        self::assertActive($vehicle);
        $lead = $this->reminderSettings->reminderPreferences($user->id)->manualDays;
        $mapped = JsonInput::reminder($body, $user->preferences, $vehicle->id, $lead);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = ManualReminderForm::parse($mapped['input'], $mapped['preferences'], [$vehicle->id]);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::REMINDER_FIELDS));
        }

        $overview = $this->reminders->overview($user);
        foreach ($overview->open as $entry) {
            $reminder = $entry->reminder;
            if (
                $reminder->vehicleId === $vehicle->id
                && $reminder->source === ReminderSource::Manual
                && mb_strtolower(trim($reminder->title)) === mb_strtolower(trim($data->title))
                && $reminder->dueOn?->format('Y-m-d') === $data->dueOn?->format('Y-m-d')
                && self::sameKm($reminder->dueKm, $data->dueKm)
            ) {
                return ['entry' => $entry, 'today' => $overview->today, 'duplicate' => true, 'warnings' => []];
            }
        }

        $reminder = $this->reminders->createManual($user, $data);

        return [
            'entry' => new ReminderEntry($reminder, $vehicle),
            'today' => $overview->today,
            'duplicate' => false,
            'warnings' => [],
        ];
    }

    /**
     * @param array<int, string> $depths tyre id → mm
     */
    private static function treadKey(DateTimeImmutable $on, array $depths): string
    {
        ksort($depths);
        $parts = [$on->format('Y-m-d')];
        foreach ($depths as $id => $mm) {
            $parts[] = $id . '=' . Decimal::trim($mm);
        }

        return implode('|', $parts);
    }

    /**
     * The form's errors under the API's names: `tread_{tyre}` → `depths.{position}`.
     *
     * @param array<string, int> $fitted position code → tyre id
     */
    private static function treadErrors(ValidationErrors $form, array $fitted): ValidationErrors
    {
        $fields = JsonInput::TREAD_CHECK_FIELDS;
        foreach ($fitted as $position => $id) {
            $fields['depths.' . $position] = 'tread_' . $id;
        }

        return JsonInput::renamed($form, $fields);
    }

    private function existingFill(Vehicle $vehicle, FuelEntryData $data): ?FuelEntry
    {
        $key = DuplicateKey::of($data);
        foreach ($this->fuel->entries($vehicle) as $entry) {
            if (DuplicateKey::of($entry->data) === $key) {
                return $entry;
            }
        }

        return null;
    }

    private static function assertActive(Vehicle $vehicle): void
    {
        if ($vehicle->isArchived()) {
            throw new ApiProblem(409, 'vehicle_archived', 'This vehicle is archived; restore it in the app to log entries.');
        }
    }

    /**
     * @return list<array{code: string, detail: string}>
     */
    private static function odometerWarnings(?OdometerWarning $warning): array
    {
        return match (true) {
            $warning === null => [],
            $warning->isBackwards() => [[
                'code' => 'odometer_backwards',
                'detail' => 'The odometer is lower than the reading before it.',
            ]],
            default => [[
                'code' => 'odometer_jump',
                'detail' => 'The odometer rose by more than 2,000 km a day since the reading before it.',
            ]],
        };
    }

    private static function sameKm(?string $a, ?string $b): bool
    {
        return ($a === null || $b === null) ? $a === $b : Decimal::compare($a, $b) === 0;
    }
}
