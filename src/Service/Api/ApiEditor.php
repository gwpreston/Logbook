<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceDocumentNotFound;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseEntryNotFound;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelEntryNotFound;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentChoices;
use Logbook\Service\Incident\IncidentForm;
use Logbook\Service\Incident\IncidentNotFound;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceEntryNotFound;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerReadingNotFound;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * Edit and delete over the API (spec.md §7.20 *Phase 39*, Phase 39.2):
 * `PATCH` lays the sent fields over the stored entry (#283) and runs the
 * result through the **edit form's parser and the service the edit page
 * calls**; `DELETE` calls the service the delete confirmation calls. So
 * validation, messages, recomputation and knock-on effects are the
 * pages' own.
 *
 * Every change runs in this order: the entry is found as its single read
 * finds it (404), `EntryAccess::canChange` (403), an archived vehicle
 * (409 `vehicle_archived`), a derived reading (409 `reading_derived`),
 * `If-Match` against the stored entry's tag (412, nothing written), then
 * the form (422).
 */
final readonly class ApiEditor
{
    public function __construct(
        private ApiEntries $entries,
        private ApiWriter $writer,
        private EntryAccess $access,
        private EntityTag $tags,
        private ValidationProblem $validation,
        private ClockInterface $clock,
        private VehicleService $vehicles,
        private StationService $stations,
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TripService $trips,
        private IncidentService $incidents,
        private IncidentChoices $incidentChoices,
    ) {
    }

    /**
     * @param value-of<ApiEntries::EDITABLE> $list
     * @param array<string, mixed> $body the decoded JSON body
     * @return array{entry: ApiEntry, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem
     */
    public function update(string $list, User $user, Vehicle $vehicle, int $id, array $body, ?string $ifMatch): array
    {
        $stored = $this->find($list, $user, $vehicle, $id);
        $this->guard($user, $vehicle, $stored, $ifMatch);
        $warnings = match ($list) {
            'fuel' => $this->updateFuel($user, $vehicle, $stored, $body),
            'odometer' => $this->updateReading($user, $vehicle, $stored, $body),
            'maintenance' => $this->updateMaintenance($user, $vehicle, $stored, $body),
            'documents' => $this->updateDocument($user, $vehicle, $stored, $body),
            'expenses' => $this->updateExpense($user, $vehicle, $stored, $body),
            'trips' => $this->updateTrip($user, $vehicle, $stored, $body),
            'incidents' => $this->updateIncident($user, $vehicle, $stored, $body),
        };

        return ['entry' => $this->entries->read($list, $user, $vehicle, $id), 'warnings' => $warnings];
    }

    /**
     * @param value-of<ApiEntries::EDITABLE> $list
     * @throws ApiProblem
     */
    public function delete(string $list, User $user, Vehicle $vehicle, int $id, ?string $ifMatch): void
    {
        $stored = $this->find($list, $user, $vehicle, $id);
        $this->guard($user, $vehicle, $stored, $ifMatch);
        $zone = $user->preferences->timeZone();
        try {
            match (true) {
                $stored instanceof FuelEntry => $this->fuel->delete($vehicle, $stored),
                $stored instanceof OdometerReading => $this->odometer->delete($vehicle, $stored),
                $stored instanceof MaintenanceEntry
                    => $this->maintenance->delete($vehicle, $stored, $zone),
                $stored instanceof ComplianceDocument
                    => $this->compliance->delete($vehicle, $stored),
                $stored instanceof ExpenseEntry => $this->expenses->delete($vehicle, $stored),
                $stored instanceof Trip => $this->trips->delete($vehicle, $stored),
                $stored instanceof Incident => $this->incidents->delete($vehicle, $stored),
                default => throw new \LogicException('No delete for ' . $stored::class . '.'),
            };
        } catch (TyreChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }
    }

    /**
     * 412 unless an `If-Match` header, when sent, names the entity's current tag
     * (spec.md §7.20 *Concurrency*, #282).
     *
     * @throws ApiProblem
     */
    public function precondition(?string $ifMatch, object $stored): void
    {
        if ($ifMatch !== null && !EntityTag::matches($ifMatch, $this->tags->of($stored))) {
            throw new ApiProblem(
                412,
                'precondition_failed',
                'The entry changed since it was read (If-Match does not match its ETag); nothing was written.',
            );
        }
    }

    /**
     * @throws ApiProblem 404
     */
    private function find(string $list, User $user, Vehicle $vehicle, int $id): object
    {
        try {
            return match ($list) {
                'fuel' => $this->fuel->get($vehicle, $id),
                'odometer' => $this->odometer->get($vehicle, $id),
                'maintenance' => $this->maintenance->get($vehicle, $id),
                'documents' => $this->compliance->get($vehicle, $id),
                'expenses' => $this->expenses->get($vehicle, $id),
                'trips' => $this->trips->get($user, $vehicle, $id),
                'incidents' => $this->incidents->get($vehicle, $id),
                default => throw new \LogicException(sprintf('No edit for "%s".', $list)),
            };
        } catch (
            FuelEntryNotFound
            | OdometerReadingNotFound
            | MaintenanceEntryNotFound
            | ComplianceDocumentNotFound
            | ExpenseEntryNotFound
            | TripNotFound
            | IncidentNotFound
        ) {
            throw ApiProblem::notFound('The vehicle has no such entry.');
        }
    }

    /**
     * @throws ApiProblem 403, 409 or 412
     */
    private function guard(User $user, Vehicle $vehicle, object $stored, ?string $ifMatch): void
    {
        $createdBy = property_exists($stored, 'createdBy') && is_int($stored->createdBy) ? $stored->createdBy : null;
        if (!$this->access->canChange($user, $vehicle, $createdBy)) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may not change this entry: it is someone else\'s.');
        }
        ApiWriter::assertActive($vehicle);
        if ($stored instanceof OdometerReading && !$stored->isManual()) {
            throw new ApiProblem(
                409,
                'reading_derived',
                'This reading belongs to another entry; change that entry instead.',
                extra: ['links' => ['entry' => self::ownerLink($vehicle, $stored)]],
            );
        }
        $this->precondition($ifMatch, $stored);
    }

    /**
     * The API path of the entry a derived reading belongs to.
     */
    private static function ownerLink(Vehicle $vehicle, OdometerReading $reading): string
    {
        $base = '/vehicles/' . $vehicle->id;

        return match (true) {
            $reading->fuelEntryId !== null => $base . '/fuel/' . $reading->fuelEntryId,
            $reading->maintenanceEntryId !== null => $base . '/maintenance/' . $reading->maintenanceEntryId,
            $reading->complianceDocumentId !== null => $base . '/documents/' . $reading->complianceDocumentId,
            $reading->incidentId !== null => $base . '/incidents/' . $reading->incidentId,
            $reading->tyreChangeId !== null => $base . '/tyres/changes',
            // Mileage when bought is the vehicle's own field (spec.md §6 OdometerReading).
            $reading->source === OdometerSource::Purchase => $base,
            default => $base,
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateFuel(User $user, Vehicle $vehicle, object $entry, array $body): array
    {
        assert($entry instanceof FuelEntry);
        $units = self::units($user->preferences, $body, ['odometer'], ['volume', 'price_per_unit']);
        $mapped = self::mapped(JsonInput::fuel($body, $units, '', $this->clock->now()), $this->validation);
        $input = JsonInput::overlay(
            FuelEntryForm::values($entry, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::FUEL_FIELDS,
        );
        // The fuel and its grade travel together in the form (one picker).
        if (array_key_exists('fuel', $body) || array_key_exists('grade', $body)) {
            $input['grade'] = $mapped['input']['grade'] ?? '';
        }
        $data = FuelEntryForm::parse($input, $mapped['preferences'], $this->vehicles->currencyFor($user, $vehicle));
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::FUEL_FIELDS));
        }
        if (
            array_key_exists('station_id', $body)
            && $data->stationId !== null
            && $this->stations->enabled()
            && $this->stations->resolve($data->stationId) === null
        ) {
            $errors = new ValidationErrors();
            $errors->add('station_id', 'api.validation.station_unknown');
            throw $this->validation->of($errors);
        }

        return $this->writer->fuelWarnings($vehicle, $this->fuel->update($vehicle, $entry, $data));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateReading(User $user, Vehicle $vehicle, object $reading, array $body): array
    {
        assert($reading instanceof OdometerReading);
        $units = self::units($user->preferences, $body, ['odometer']);
        $mapped = self::mapped(JsonInput::reading($body, $units, $this->clock->now()), $this->validation);
        $input = JsonInput::overlay(
            OdometerReadingForm::values($reading, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::READING_FIELDS,
        );
        $data = OdometerReadingForm::parse($input, $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::READING_FIELDS));
        }
        $this->odometer->update($vehicle, $reading, $data);

        return ApiWriter::odometerWarnings($this->odometer->warningFor($vehicle, $reading->id));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateMaintenance(User $user, Vehicle $vehicle, object $entry, array $body): array
    {
        assert($entry instanceof MaintenanceEntry);
        $zone = $user->preferences->timeZone();
        $units = self::units($user->preferences, $body, ['odometer']);
        $mapped = self::mapped(JsonInput::maintenance($body, $units, LocalTime::today($this->clock, $zone)), $this->validation);
        $input = JsonInput::overlay(
            MaintenanceEntryForm::values($entry, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::MAINTENANCE_FIELDS,
        );
        $scheduleIds = array_map(static fn (MaintenanceSchedule $s): int => $s->id, $this->schedules->list($vehicle));
        $data = MaintenanceEntryForm::parse($input, $mapped['preferences'], $scheduleIds);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::MAINTENANCE_FIELDS));
        }
        try {
            // The incident link is the form's own field, not the API's: it stays as it is.
            $updated = $this->maintenance->update($vehicle, $entry, $data, $zone);
        } catch (TyreChangeRefused $refused) {
            throw $this->validation->of(JsonInput::renamed(ApiWriter::refusal($refused), JsonInput::MAINTENANCE_FIELDS));
        }

        return ApiWriter::odometerWarnings($this->maintenance->odometerWarning($vehicle, $updated));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateDocument(User $user, Vehicle $vehicle, object $document, array $body): array
    {
        assert($document instanceof ComplianceDocument);
        $units = self::units($user->preferences, $body, ['odometer']);
        $mapped = self::mapped(JsonInput::document($body, $units), $this->validation);
        $input = JsonInput::overlay(
            ComplianceDocumentForm::values($document, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::DOCUMENT_FIELDS,
        );
        $data = ComplianceDocumentForm::parse($input, $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::DOCUMENT_FIELDS));
        }
        $updated = $this->compliance->update($vehicle, $document, $data, $user->preferences->timeZone());

        return ApiWriter::odometerWarnings($this->compliance->odometerWarning($vehicle, $updated));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateExpense(User $user, Vehicle $vehicle, object $entry, array $body): array
    {
        assert($entry instanceof ExpenseEntry);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $mapped = self::mapped(JsonInput::expense($body, $user->preferences, $today), $this->validation);
        $input = JsonInput::overlay(ExpenseEntryForm::values($entry), $body, $mapped['input'], JsonInput::EXPENSE_FIELDS);
        $data = ExpenseEntryForm::parse($input, $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::EXPENSE_FIELDS));
        }
        // The incident link is the form's own field, not the API's: it stays as it is.
        $this->expenses->update($vehicle, $entry, $data);

        return [];
    }

    /**
     * A trip in kilometres, the whole trip's distance, as the API logs it
     * (spec.md §7.22). A saved journey fills a new trip only: `journey_id`
     * is refused here.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateTrip(User $user, Vehicle $vehicle, object $trip, array $body): array
    {
        assert($trip instanceof Trip);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        if (array_key_exists('journey_id', $body)) {
            $errors = new ValidationErrors();
            $errors->add('journey_id', 'api.validation.unknown_field');
            throw $this->validation->of($errors);
        }
        $mapped = self::mapped(JsonInput::trip($body, $user->preferences, $today, static fn (): null => null), $this->validation);
        $stored = TripForm::values($trip, $mapped['preferences']);
        $data = $trip->data;
        if ($data->odometerStartKm === null || $data->odometerEndKm === null) {
            // The edit form shows one way on a return; the API reads the whole trip.
            $stored['distance'] = Decimal::trim($data->distanceKm);
        }
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::TRIP_FIELDS);
        $parsed = TripForm::parse($input, $mapped['preferences'], $today, wholeDistance: true);
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($parsed, JsonInput::TRIP_FIELDS));
        }
        $this->trips->update($vehicle, $trip, $parsed);

        return $this->writer->tripWarnings($vehicle, $parsed, $zone);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     */
    private function updateIncident(User $user, Vehicle $vehicle, object $incident, array $body): array
    {
        assert($incident instanceof Incident);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $units = self::units($user->preferences, $body, ['odometer']);
        $mapped = self::mapped(JsonInput::incident($body, $units, $today), $this->validation);
        $stored = IncidentForm::values($incident, $this->incidents->odometerOf($vehicle, $incident), $mapped['preferences']);
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::INCIDENT_FIELDS);
        $parsed = IncidentForm::parse(
            $input,
            $mapped['preferences'],
            $today,
            $this->incidentChoices->driverIds($vehicle),
            $this->incidentChoices->policyIds($vehicle),
        );
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($parsed, JsonInput::INCIDENT_FIELDS));
        }
        $this->incidents->update($vehicle, $incident, $parsed->data, $parsed->odometerKm, $zone);

        return [];
    }

    /**
     * The units the stored entry is laid out in (#283): the owner's (or the
     * body's) for a dimension the body sends, else the canonical km and
     * litres, so a field not sent round-trips exactly. Never through miles
     * or gallons and back, which can move the third decimal place.
     *
     * @param array<string, mixed> $body
     * @param list<string> $distance the body's fields in a distance unit
     * @param list<string> $volume the body's fields in a volume unit
     */
    private static function units(DisplayPreferences $owner, array $body, array $distance, array $volume = []): DisplayPreferences
    {
        return new DisplayPreferences(
            $owner->locale,
            $owner->timezone,
            self::sends($body, [...$distance, 'distance_unit']) ? $owner->distanceUnit : DistanceUnit::Kilometre,
            self::sends($body, [...$volume, 'volume_unit']) ? $owner->volumeUnit : VolumeUnit::Litre,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
            $owner->accent,
            $owner->depthUnit,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $fields
     */
    private static function sends(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of array
     * @param T|ValidationErrors $mapped
     * @return T
     * @throws ApiProblem 422
     */
    private static function mapped(array|ValidationErrors $mapped, ValidationProblem $validation): array
    {
        if ($mapped instanceof ValidationErrors) {
            throw $validation->of($mapped);
        }

        return $mapped;
    }
}
