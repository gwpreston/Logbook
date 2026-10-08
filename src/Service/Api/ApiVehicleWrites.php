<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\NewVehicle;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use Logbook\Service\Vehicle\PurchaseMileageNeedsDate;
use Logbook\Service\Vehicle\TyresBlockTypeChange;
use Logbook\Service\Vehicle\VehicleArchiving;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\InspectionRules;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * Vehicles over the API (Phase 39.2, spec.md §7.20, #284): create through
 * the add form (the key's user becomes the owner), edit through the edit
 * form (`Manage`), archive through the *Archive* page's rules and restore
 * (`Own`). No delete: that stays behind the page's confirmation.
 */
final readonly class ApiVehicleWrites
{
    /** A retried create within this many seconds is the same vehicle (#288). */
    public const int DUPLICATE_SECONDS = 600;

    public function __construct(
        private VehicleService $vehicles,
        private VehicleRepository $repository,
        private VehicleArchiving $archiving,
        private FirstInspectionPrompt $prompt,
        private FirstInspection $firstInspection,
        private FeatureToggles $features,
        private ApiReader $reader,
        private ApiEditor $editor,
        private EntityTag $tags,
        private ValidationProblem $validation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `POST /vehicles`. The same owner, registration (when given), make and
     * model created in the last ten minutes is a retry: that vehicle comes
     * back with `duplicate: true`.
     *
     * @param array<string, mixed> $body
     * @return array{vehicle: Vehicle, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 422
     */
    public function create(User $user, array $body): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $mapped = JsonInput::vehicle($body, $user->preferences, true);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $withFirstInspection = $this->features->isEnabled(Feature::Compliance);
        $new = VehicleForm::parseNew($mapped['input'], $mapped['preferences'], $today, $withFirstInspection);
        if ($new instanceof ValidationErrors) {
            throw $this->validation->of($new);
        }
        if ($withFirstInspection && !array_key_exists('first_inspection_due_on', $body)) {
            // The suggestion follows the owner's country, not the API's "en" for reading numbers.
            $suggested = InspectionRules::suggest($user->preferences->locale, $new->data->firstRegisteredOn, $today);
            $new = new NewVehicle(
                $new->data->withFirstInspectionDueOn($suggested),
                $new->startingReading,
                $suggested,
                $new->purchaseKm,
            );
        }

        $existing = $this->recent($user, $new->data->registration, $new->data->make, $new->data->model);
        if ($existing !== null) {
            return ['vehicle' => $existing, 'duplicate' => true, 'warnings' => []];
        }
        try {
            $vehicle = $this->vehicles->create($user, $new->data, $new->startingReading, purchaseKm: $new->purchaseKm);
        } catch (PaperworkNeedsDate | PurchaseMileageNeedsDate $refused) {
            throw $this->validation->of(self::refusal($refused));
        }
        if ($withFirstInspection) {
            // The field was offered: the one-time prompt never asks about this vehicle.
            $this->prompt->settle($vehicle);
        }

        $warnings = self::modelYear(VehicleForm::modelYearWarning($new->data));
        if (VehicleForm::startingReadingWarning($new)) {
            $warnings[] = [
                'code' => 'reading_before_registration',
                'detail' => 'The starting reading is dated before first registration.',
            ];
        }
        if ($new->purchaseKm !== null) {
            $warnings = [...$warnings, ...ApiWriter::odometerWarnings($this->vehicles->purchaseWarning($vehicle))];
        }

        return ['vehicle' => $vehicle, 'duplicate' => false, 'warnings' => $warnings];
    }

    /**
     * `PATCH /vehicles/{id}`: the edit form, `Manage`. A sale date marks the
     * vehicle *Sold*; clearing it clears that (the form's rule).
     *
     * @param array<string, mixed> $body
     * @return array{vehicle: Vehicle, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409, 412, 422
     */
    public function update(User $user, Vehicle $vehicle, array $body, ?string $ifMatch): array
    {
        ApiWriter::assertActive($vehicle);
        $this->editor->precondition($ifMatch, $vehicle);
        $owner = $user->preferences;
        $sends = static fn (string ...$fields): bool => array_intersect($fields, array_keys($body)) !== [];
        $units = new DisplayPreferences(
            $owner->locale,
            $owner->timezone,
            $sends('purchase_odometer', 'distance_unit') ? $owner->distanceUnit : DistanceUnit::Kilometre,
            $sends('capacity', 'volume_unit') ? $owner->volumeUnit : VolumeUnit::Litre,
            $owner->consumptionUnit,
            $owner->currency,
        );
        $mapped = JsonInput::vehicle($body, $units, false);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $stored = VehicleForm::values($vehicle, $mapped['preferences'], $this->vehicles->purchaseReading($vehicle)?->readingKm);
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::VEHICLE_FIELDS);
        // Off the form (compliance off, or read-only after the first certificate), the stored date stays.
        $withFirstInspection = $this->features->isEnabled(Feature::Compliance)
            && !$this->firstInspection->vehicleHasCertificate($vehicle);
        if (!$withFirstInspection && array_key_exists('first_inspection_due_on', $body)) {
            $errors = new ValidationErrors();
            $errors->add('first_inspection_due_on', 'api.validation.unknown_field');
            throw $this->validation->of($errors);
        }
        $today = LocalTime::today($this->clock, $owner->timeZone());
        $edit = VehicleForm::parseEdit(
            $input,
            $mapped['preferences'],
            $today,
            $withFirstInspection,
            $vehicle->data->firstInspectionDueOn,
        );
        if ($edit instanceof ValidationErrors) {
            throw $this->validation->of($edit);
        }
        try {
            $updated = $this->vehicles->update($user, $vehicle, $edit->data, purchaseMileage: $edit->purchaseMileage);
        } catch (TyresBlockTypeChange $refused) {
            $errors = new ValidationErrors();
            $errors->add('type', 'vehicle.error.type_tyres', [
                'type' => $refused->type->value,
                'position' => $refused->position->value,
            ]);
            throw $this->validation->of($errors);
        } catch (PaperworkNeedsDate | PurchaseMileageNeedsDate $refused) {
            throw $this->validation->of(self::refusal($refused));
        }
        if ($withFirstInspection) {
            $this->prompt->settle($updated);
        }

        return [
            'vehicle' => $updated,
            'warnings' => [
                ...self::modelYear(VehicleForm::modelYearWarning($edit->data)),
                ...ApiWriter::odometerWarnings($this->vehicles->purchaseWarning($updated)),
            ],
        ];
    }

    /**
     * `POST /vehicles/{id}/archive`: the *Archive* page (§7.1, §7.29, §7.32),
     * `Own`. No `disposal` just archives.
     *
     * @param array<string, mixed> $body
     * @throws ApiProblem 409 when archived already, 422 with the page's messages
     */
    public function archive(User $user, Vehicle $vehicle, array $body): Vehicle
    {
        ApiWriter::assertActive($vehicle);
        $input = JsonInput::archive($body);
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        $disposal = $input['disposal'];
        $choices = $this->archiving->options($user, $vehicle)['choices'];
        if ($disposal !== '' && !in_array($disposal, $choices, true)) {
            $errors = new ValidationErrors();
            $errors->add('disposal', 'validation.choice');
            throw $this->validation->of($errors);
        }
        $done = $this->archiving->archive($user, $vehicle, $input);
        if ($done instanceof ValidationErrors) {
            throw $this->validation->of($done);
        }

        return $this->vehicles->get($user, $vehicle->id);
    }

    /**
     * `POST /vehicles/{id}/restore`: *Restore*, `Own`; an active vehicle is left as it is.
     */
    public function restore(User $user, Vehicle $vehicle): Vehicle
    {
        if ($vehicle->isArchived()) {
            $this->vehicles->restore($user, $vehicle);
        }

        return $this->vehicles->get($user, $vehicle->id);
    }

    /**
     * The vehicle as `GET /vehicles/{id}` returns it, and its tag.
     *
     * @return array{body: array<string, mixed>, tag: string}
     */
    public function read(User $user, Vehicle $vehicle): array
    {
        return ['body' => $this->reader->vehicle($user, $vehicle), 'tag' => $this->tags->of($vehicle)];
    }

    private function recent(User $user, ?string $registration, string $make, string $model): ?Vehicle
    {
        $since = $this->clock->now()->getTimestamp() - self::DUPLICATE_SECONDS;
        $same = static fn (?string $a, ?string $b): bool => mb_strtolower(trim($a ?? '')) === mb_strtolower(trim($b ?? ''));
        foreach ($this->repository->listByIds($this->repository->idsOwnedBy($user->id, null)) as $vehicle) {
            if (
                $vehicle->createdAt->getTimestamp() >= $since
                && ($registration === null || $same($vehicle->data->registration, $registration))
                && $same($vehicle->data->make, $make)
                && $same($vehicle->data->model, $model)
            ) {
                return $vehicle;
            }
        }

        return null;
    }

    private static function refusal(PaperworkNeedsDate|PurchaseMileageNeedsDate $refused): ValidationErrors
    {
        $errors = new ValidationErrors();
        $errors->add($refused->field(), $refused->messageKey());

        return $errors;
    }

    /**
     * @param array{year: string, registered: string}|null $warning
     * @return list<array{code: string, detail: string}>
     */
    private static function modelYear(?array $warning): array
    {
        return $warning === null ? [] : [[
            'code' => 'model_year_after_registration',
            'detail' => sprintf(
                'The model year %s is more than a year after first registration (%s); check it.',
                $warning['year'],
                $warning['registered'],
            ),
        ]];
    }
}
