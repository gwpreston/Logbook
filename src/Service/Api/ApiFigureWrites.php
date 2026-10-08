<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\MaintenanceScheduleForm;
use Logbook\Service\Maintenance\MaintenanceScheduleNotFound;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Valuation\ValuationForm;
use Logbook\Service\Valuation\ValuationNotFound;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * Valuations and maintenance schedules over the API (Phase 39.2, spec.md
 * §7.20), both `Manage`, through the valuation and schedule forms and
 * their services, as the pages. A create the same as one already stored
 * (a valuation's date, amount and source; a schedule's category, title
 * and intervals) is not written again.
 *
 * A valuation is the one write an archived vehicle accepts (§7.1: a
 * scrapped car's scrap value), within the form's sale-date rule; a
 * schedule on an archived vehicle is refused (409).
 */
final readonly class ApiFigureWrites
{
    public function __construct(
        private ValuationService $valuations,
        private ScheduleService $schedules,
        private ApiVehicleFigures $figures,
        private ApiEditor $editor,
        private ValidationProblem $validation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{entry: ApiEntry, duplicate: bool}
     * @throws ApiProblem 422
     */
    public function createValuation(User $user, Vehicle $vehicle, array $body): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $mapped = JsonInput::valuation($body, $user->preferences, $today);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = ValuationForm::parse($mapped['input'], $mapped['preferences'], $today, $vehicle);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::VALUATION_FIELDS));
        }
        foreach ($this->valuations->forVehicle($vehicle) as $stored) {
            if (
                $stored->data->valuedOn == $data->valuedOn
                && Decimal::compare($stored->data->amount, $data->amount) === 0
                && mb_strtolower(trim($stored->data->source ?? '')) === mb_strtolower(trim($data->source ?? ''))
            ) {
                return ['entry' => $this->figures->valuation($user, $vehicle, $stored->id), 'duplicate' => true];
            }
        }
        $valuation = $this->valuations->create($vehicle, $data);

        return ['entry' => $this->figures->valuation($user, $vehicle, $valuation->id), 'duplicate' => false];
    }

    /**
     * @param array<string, mixed> $body
     * @throws ApiProblem 404, 412, 422
     */
    public function updateValuation(User $user, Vehicle $vehicle, int $id, array $body, ?string $ifMatch): ApiEntry
    {
        $valuation = $this->valuation($vehicle, $id);
        $this->editor->precondition($ifMatch, $valuation);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $mapped = JsonInput::valuation($body, $user->preferences, $today);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = JsonInput::overlay(ValuationForm::values($valuation), $body, $mapped['input'], JsonInput::VALUATION_FIELDS);
        $data = ValuationForm::parse($input, $mapped['preferences'], $today, $vehicle);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::VALUATION_FIELDS));
        }
        $this->valuations->update($vehicle, $valuation, $data);

        return $this->figures->valuation($user, $vehicle, $id);
    }

    /**
     * @throws ApiProblem 404, 412
     */
    public function deleteValuation(Vehicle $vehicle, int $id, ?string $ifMatch): void
    {
        $valuation = $this->valuation($vehicle, $id);
        $this->editor->precondition($ifMatch, $valuation);
        $this->valuations->delete($vehicle, $valuation);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{entry: ApiEntry, duplicate: bool}
     * @throws ApiProblem 409, 422
     */
    public function createSchedule(User $user, Vehicle $vehicle, array $body): array
    {
        ApiWriter::assertActive($vehicle);
        $mapped = JsonInput::schedule($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $data = MaintenanceScheduleForm::parse($mapped['input'], $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(self::scheduleErrors($data, $body));
        }
        foreach ($this->schedules->list($vehicle) as $stored) {
            if (
                $stored->data->category === $data->category
                && mb_strtolower(trim($stored->data->title)) === mb_strtolower(trim($data->title))
                && self::same($stored->data->intervalKm, $data->intervalKm)
                && $stored->data->intervalMonths === $data->intervalMonths
            ) {
                return ['entry' => $this->figures->schedule($user, $vehicle, $stored->id), 'duplicate' => true];
            }
        }
        $schedule = $this->schedules->create($vehicle, $data);

        return ['entry' => $this->figures->schedule($user, $vehicle, $schedule->id), 'duplicate' => false];
    }

    /**
     * @param array<string, mixed> $body
     * @throws ApiProblem 404, 409, 412, 422
     */
    public function updateSchedule(User $user, Vehicle $vehicle, int $id, array $body, ?string $ifMatch): ApiEntry
    {
        $schedule = $this->schedule($vehicle, $id);
        ApiWriter::assertActive($vehicle);
        $this->editor->precondition($ifMatch, $schedule);
        $mapped = JsonInput::schedule($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = JsonInput::overlay(
            MaintenanceScheduleForm::values($schedule, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::SCHEDULE_FIELDS,
        );
        $data = MaintenanceScheduleForm::parse($input, $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(self::scheduleErrors($data, $body));
        }
        $this->schedules->update($vehicle, $schedule, $data);

        return $this->figures->schedule($user, $vehicle, $id);
    }

    /**
     * Deleting keeps the records that completed it (spec.md §7.4).
     *
     * @throws ApiProblem 404, 409, 412
     */
    public function deleteSchedule(Vehicle $vehicle, int $id, ?string $ifMatch): void
    {
        $schedule = $this->schedule($vehicle, $id);
        ApiWriter::assertActive($vehicle);
        $this->editor->precondition($ifMatch, $schedule);
        $this->schedules->delete($vehicle, $schedule);
    }

    private function valuation(Vehicle $vehicle, int $id): VehicleValuation
    {
        try {
            return $this->valuations->get($vehicle, $id);
        } catch (ValuationNotFound) {
            throw ApiProblem::notFound('The vehicle has no such valuation.');
        }
    }

    private function schedule(Vehicle $vehicle, int $id): MaintenanceSchedule
    {
        try {
            return $this->schedules->get($vehicle, $id);
        } catch (MaintenanceScheduleNotFound) {
            throw ApiProblem::notFound('The vehicle has no such schedule.');
        }
    }

    /**
     * The form's errors under the API's names; an interval error under the field the body used.
     *
     * @param array<string, mixed> $body
     */
    private static function scheduleErrors(ValidationErrors $form, array $body): ValidationErrors
    {
        $fields = JsonInput::SCHEDULE_FIELDS;
        if (!array_key_exists('interval_distance', $body)) {
            unset($fields['interval_distance']);
        } else {
            unset($fields['interval_km']);
        }

        return JsonInput::renamed($form, $fields);
    }

    private static function same(?string $a, ?string $b): bool
    {
        return ($a === null || $b === null) ? $a === $b : Decimal::compare($a, $b) === 0;
    }
}
