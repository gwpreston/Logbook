<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use DateTimeImmutable;
use LogicException;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Psr\Clock\ClockInterface;

/**
 * A vehicle's mileage as one coherent series (spec.md §7.2): manual readings
 * are managed here directly; fill-ups record theirs through recordFor…(), so
 * every source lands in the same table and the same history.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner.
 */
final readonly class OdometerService
{
    public function __construct(
        private OdometerReadingRepository $readings,
        private ClockInterface $clock,
    ) {
    }

    public function history(Vehicle $vehicle): OdometerHistory
    {
        return new OdometerHistory($this->readings->listForVehicle($vehicle->id));
    }

    /**
     * @throws OdometerReadingNotFound
     */
    public function get(Vehicle $vehicle, int $id): OdometerReading
    {
        return $this->readings->find($vehicle->id, $id)
            ?? throw new OdometerReadingNotFound(sprintf('Odometer reading %d not found.', $id));
    }

    public function create(Vehicle $vehicle, OdometerReadingData $data): OdometerReading
    {
        $id = $this->readings->insert($vehicle->id, $data, OdometerSource::Manual, null, $this->clock->now());

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, OdometerReading $reading, OdometerReadingData $data): OdometerReading
    {
        self::assertManual($reading);
        $this->readings->update($vehicle->id, $reading->id, $data, $this->clock->now());

        return $this->get($vehicle, $reading->id);
    }

    public function delete(Vehicle $vehicle, OdometerReading $reading): void
    {
        self::assertManual($reading);
        $this->readings->delete($vehicle->id, $reading->id);
    }

    /**
     * Create or move the reading that belongs to a fill-up. Call inside the
     * fill-up's transaction.
     */
    public function recordForFuelEntry(Vehicle $vehicle, int $fuelEntryId, string $km, DateTimeImmutable $at): void
    {
        $data = new OdometerReadingData($km, $at);
        $existing = $this->readings->findByFuelEntry($vehicle->id, $fuelEntryId);

        if ($existing === null) {
            $this->readings->insert($vehicle->id, $data, OdometerSource::Fuel, $fuelEntryId, $this->clock->now());
        } else {
            $this->readings->update($vehicle->id, $existing->id, $data, $this->clock->now());
        }
    }

    public function forgetFuelEntry(Vehicle $vehicle, int $fuelEntryId): void
    {
        $existing = $this->readings->findByFuelEntry($vehicle->id, $fuelEntryId);
        if ($existing !== null) {
            $this->readings->delete($vehicle->id, $existing->id);
        }
    }

    public function readingForFuelEntry(Vehicle $vehicle, int $fuelEntryId): ?OdometerReading
    {
        return $this->readings->findByFuelEntry($vehicle->id, $fuelEntryId);
    }

    /**
     * Plausibility warning for one reading within the vehicle's series.
     */
    public function warningFor(Vehicle $vehicle, int $readingId): ?OdometerWarning
    {
        return $this->history($vehicle)->warningFor($readingId);
    }

    private static function assertManual(OdometerReading $reading): void
    {
        if (!$reading->isManual()) {
            throw new LogicException('Only manual readings are edited directly; change the entry that owns it.');
        }
    }
}
