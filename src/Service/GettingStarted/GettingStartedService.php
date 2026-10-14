<?php

declare(strict_types=1);

namespace Logbook\Service\GettingStarted;

use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\GettingStarted\StartStep;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceScheduleForm;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * *Set up your first vehicle* (spec.md §7.9): which step is next, and each
 * step's save, always through the services the full forms use (the vehicle
 * is made by VehicleService, a reading by OdometerService, the key dates by
 * ComplianceService, the schedule by ScheduleService), so what it makes is
 * like anything else in the garage. A step switched off with its module is
 * not offered.
 */
final readonly class GettingStartedService
{
    /** A common interval; the owner's handbook has the right one (decision D, #414). */
    public const int PRESET_MONTHS = 12;
    private const int PRESET_MILES = 10000;
    private const int PRESET_KM = 16000;

    public function __construct(
        private GettingStartedStore $store,
        private FeatureToggles $features,
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private ComplianceService $compliance,
        private ScheduleService $schedules,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function progress(User $user): GettingStartedProgress
    {
        return $this->store->load($user->id);
    }

    /**
     * The steps on offer, in order: key dates need `compliance`, servicing `maintenance`.
     *
     * @return list<StartStep>
     */
    public function steps(): array
    {
        return array_values(array_filter(
            StartStep::cases(),
            fn (StartStep $step): bool => match ($step) {
                StartStep::Dates => $this->features->isEnabled(Feature::Compliance),
                StartStep::Servicing => $this->features->isEnabled(Feature::Maintenance),
                default => true,
            },
        ));
    }

    /**
     * The vehicle the flow made, while it is still the user's own.
     */
    public function vehicle(User $user, GettingStartedProgress $progress): ?Vehicle
    {
        if ($progress->vehicleId === null) {
            return null;
        }
        try {
            $vehicle = $this->vehicles->get($user, $progress->vehicleId);
        } catch (VehicleNotFound) {
            return null;
        }

        return $vehicle->userId === $user->id ? $vehicle : null;
    }

    /**
     * The first step not yet saved or skipped; the vehicle step again when the vehicle is gone.
     */
    public function next(GettingStartedProgress $progress, ?Vehicle $vehicle): StartStep
    {
        if ($vehicle === null) {
            return StartStep::Vehicle;
        }
        foreach ($this->steps() as $step) {
            if (!$progress->isDone($step) && !$progress->isSkipped($step)) {
                return $step;
            }
        }

        return StartStep::Done;
    }

    /**
     * Whether the user can be taken into the flow: not dismissed, and no vehicle of the flow's own.
     */
    public function wanted(User $user): bool
    {
        $progress = $this->progress($user);

        return !$progress->dismissed && $this->vehicle($user, $progress) === null
            && $this->vehicles->counts($user) === ['active' => 0, 'archived' => 0];
    }

    /**
     * The card for the vehicle the flow made, until all its steps are done or it is dismissed.
     */
    public function card(User $user, Vehicle $vehicle): ?StartCard
    {
        $progress = $this->progress($user);
        if ($progress->dismissed || $progress->vehicleId !== $vehicle->id || $vehicle->userId !== $user->id) {
            return null;
        }
        $steps = $this->steps();
        $done = 0;
        $missing = [];
        foreach ($steps as $step) {
            if ($progress->isDone($step) || ($step === StartStep::Done && $this->next($progress, $vehicle) === StartStep::Done)) {
                $done++;
            } else {
                $missing[] = $step;
            }
        }

        return $missing === [] ? null : new StartCard($done, count($steps), $missing);
    }

    public function dismiss(User $user): void
    {
        $this->store->save($user->id, $this->progress($user)->dismissed());
    }

    /**
     * The last step was reached: the flow is complete.
     */
    public function finish(User $user): void
    {
        $this->store->save($user->id, $this->progress($user)->withDone(StartStep::Done));
    }

    public function skip(User $user, StartStep $step): void
    {
        $this->store->save($user->id, $this->progress($user)->withSkipped($step));
    }

    /**
     * Step 1 saved: the vehicle is the flow's.
     */
    public function vehicleMade(User $user, Vehicle $vehicle, ?string $motExpiresOn): void
    {
        $this->store->save($user->id, $this->progress($user)->withVehicle($vehicle->id, $motExpiresOn));
    }

    /**
     * Step 2: one number, dated now, as a mileage-log reading.
     *
     * @param array<array-key, mixed> $input
     */
    public function saveMileage(User $user, Vehicle $vehicle, array $input): OdometerReading|ValidationErrors
    {
        $now = $this->clock->now();
        $zone = $user->preferences->timeZone();
        $input['recorded_at'] = LocalTime::fromUtc($now, $zone)->format(OdometerReadingForm::LOCAL_FORMAT);
        $data = OdometerReadingForm::parse($input, $user->preferences);
        if ($data instanceof ValidationErrors) {
            return $data;
        }
        $reading = $this->odometer->create($vehicle, $data);
        $this->store->save($user->id, $this->progress($user)->withDone(StartStep::Mileage));

        return $reading;
    }

    /**
     * Step 3: MOT, tax and insurance as documents holding only their expiry (and the insurer);
     * each raises its usual reminder. All blank saves nothing and counts as skipped.
     *
     * @param array<array-key, mixed> $input
     * @return int|ValidationErrors how many documents were made
     */
    public function saveDates(User $user, Vehicle $vehicle, array $input): int|ValidationErrors
    {
        $validator = new Validator($input, $user->preferences->locale);
        $mot = $validator->date('mot_due_on');
        $tax = $validator->date('tax_due_on');
        $insurance = $validator->date('insurance_due_on');
        $insurer = $validator->string('insurer', false, 100);
        if (!$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        $documents = [];
        if ($mot !== null) {
            $documents[] = new ComplianceDocumentData(ComplianceType::Inspection, expiryOn: $mot);
        }
        if ($tax !== null) {
            $documents[] = new ComplianceDocumentData(
                ComplianceType::Other,
                title: $this->translator->trans('getting_started.dates.tax_title', [], null, $user->preferences->locale),
                expiryOn: $tax,
            );
        }
        if ($insurance !== null) {
            $documents[] = new ComplianceDocumentData(ComplianceType::Insurance, provider: $insurer, expiryOn: $insurance);
        }
        foreach ($documents as $data) {
            $this->compliance->create($vehicle, $data, $user->preferences->timeZone());
        }
        $progress = $this->progress($user);
        $this->store->save(
            $user->id,
            $documents === [] ? $progress->withSkipped(StartStep::Dates) : $progress->withDone(StartStep::Dates),
        );

        return count($documents);
    }

    /**
     * The servicing preset in the owner's own unit: 12 months or 10,000 miles (16,000 km).
     *
     * @return array<string, string>
     */
    public function servicingDefaults(User $user): array
    {
        $miles = $user->preferences->distanceUnit === DistanceUnit::Mile;

        return [
            'interval_distance' => (string) ($miles ? self::PRESET_MILES : self::PRESET_KM),
            'interval_months' => (string) self::PRESET_MONTHS,
        ];
    }

    /**
     * Step 4: a service schedule. Its first due point is from the last service date, else the
     * vehicle's purchase date, else today; the mileage now is the km point only for today.
     *
     * @param array<array-key, mixed> $input
     */
    public function saveServicing(User $user, Vehicle $vehicle, array $input): bool|ValidationErrors
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $input['title'] = $this->translator->trans('getting_started.servicing.title', [], null, $user->preferences->locale);
        $input['category'] = MaintenanceCategory::Service->value;
        $input['last_done_odometer'] = '';
        $data = MaintenanceScheduleForm::parse($input, $user->preferences);
        if ($data instanceof ValidationErrors) {
            return $data;
        }

        $on = $data->baselineDoneOn ?? $vehicle->data->purchaseDate ?? $today;
        // The mileage now belongs only to "today": an older date has no matching reading.
        $km = $on === $today ? $this->odometer->history($vehicle)->latest()?->readingKm : null;
        $this->schedules->create($vehicle, new MaintenanceScheduleData(
            category: $data->category,
            title: $data->title,
            intervalKm: $data->intervalKm,
            intervalMonths: $data->intervalMonths,
            baselineDoneOn: $on,
            baselineDoneKm: $km,
        ));
        $this->store->save($user->id, $this->progress($user)->withDone(StartStep::Servicing));

        return true;
    }
}
