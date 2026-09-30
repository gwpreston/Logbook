<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

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
 * The API's writes (spec.md §7.20): a fill-up, an odometer reading and a
 * trip (Phase 22).
 * Each goes through its form's parser and its service, exactly as the
 * forms and the CSV import do, so validation, the derived amount, the
 * odometer reading, schedules, reminders and the economy check all apply.
 * An entry the CSV import would call a duplicate is not written again:
 * the existing one is returned, so a retry never doubles anything.
 */
final readonly class ApiWriter
{
    public function __construct(
        private FuelService $fuel,
        private OdometerService $odometer,
        private VehicleService $vehicles,
        private TripService $trips,
        private TripRepository $tripEntries,
        private SavedJourneyService $journeys,
        private ValidationProblem $validation,
        private ClockInterface $clock,
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
}
