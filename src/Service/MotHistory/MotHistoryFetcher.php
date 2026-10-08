<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Database\Transaction;
use Psr\Clock\ClockInterface;

/**
 * Fetching a vehicle's MOT history (spec.md §7.38 *Fetching*, *Mileage*):
 * by registration, then by VIN; refused when the make disagrees; tests
 * upserted by number, each read odometer a `mot` reading at the test's
 * instant. Nothing is sent unless the provider is on and the owner has
 * confirmed for this vehicle. Access (`Own`) is checked by the route.
 */
final readonly class MotHistoryFetcher
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotHistoryCalls $calls,
        private MotTestRepository $tests,
        private OdometerService $odometer,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The owner's confirmation, stored once (`mot_history_enabled_at`).
     */
    public function confirm(Vehicle $vehicle): void
    {
        if (!$this->tests->state($vehicle->id)->enabled()) {
            $this->tests->enable($vehicle->id, $this->clock->now());
        }
    }

    /**
     * @throws MotHistoryUnavailable while the provider is off, before the
     *   owner's confirmation, or with no registration or VIN
     * @throws MotHistoryFailure when the provider can't be asked
     */
    public function fetch(Vehicle $vehicle): FetchOutcome
    {
        $provider = $this->config->provider() ?? throw new MotHistoryUnavailable('off');
        if (!$this->tests->state($vehicle->id)->enabled()) {
            throw new MotHistoryUnavailable('unconfirmed');
        }
        $plate = VehicleIdentifier::registration($vehicle->data->registration);
        $vin = VehicleIdentifier::vin($vehicle->data->vin);
        if ($plate === null && $vin === null) {
            throw new MotHistoryUnavailable('no_identifier');
        }

        $record = $this->calls->run($provider, static function (MotHistoryClient $client) use ($plate, $vin): ?MotVehicleRecord {
            $record = $plate === null ? null : $client->byRegistration($plate);

            return $record ?? ($vin === null ? null : $client->byVin($vin));
        });
        if ($record === null) {
            return new FetchOutcome(false);
        }

        $knownAs = null;
        $dvsaPlate = VehicleIdentifier::registration($record->registration);
        if ($plate !== null && $dvsaPlate !== null && $dvsaPlate !== $plate) {
            $knownAs = $record->registration;
        }
        if (!MakeMatch::agrees($vehicle->data->make, $record->make)) {
            $refusedAs = trim(($record->make ?? '') . ' ' . ($record->model ?? ''));

            return new FetchOutcome(true, refusedAs: $refusedAs, knownAs: $knownAs);
        }
        $modelAs = MakeMatch::modelDiffers($vehicle->data->model, $record->model) ? $record->model : null;

        return $this->store($vehicle, $record, $knownAs, $modelAs);
    }

    /**
     * *Stop and remove*: the tests, their defects and readings, the recall
     * state and the confirmation. Issues and documents made from them stay.
     */
    public function stop(Vehicle $vehicle): void
    {
        $this->transaction->run(function () use ($vehicle): void {
            $this->tests->deleteForVehicle($vehicle->id);
            $this->tests->clearVehicle($vehicle->id);
        });
    }

    private function store(Vehicle $vehicle, MotVehicleRecord $record, ?string $knownAs, ?string $modelAs): FetchOutcome
    {
        return $this->transaction->run(function () use ($vehicle, $record, $knownAs, $modelAs): FetchOutcome {
            $now = $this->clock->now();
            $added = 0;
            foreach ($record->tests as $test) {
                [$id, $new] = $this->tests->upsert($vehicle->id, $test, $now);
                $added += $new ? 1 : 0;
                // Every read odometer, pass or fail (#322); none when it wasn't read.
                $this->odometer->recordForEntry($vehicle, OdometerSource::Mot, $id, $test->odometerKm, $test->completedAt);
            }
            $this->tests->saveFetch(
                $vehicle->id,
                $now,
                $record->recall,
                $record->tests === [] ? $record->firstDueOn : null,
            );

            return new FetchOutcome(
                true,
                $added,
                count($record->tests) - $added,
                $record->undated,
                $knownAs,
                null,
                $modelAs,
            );
        });
    }
}
