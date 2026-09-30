<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;

/**
 * A logged journey (spec.md §6 Trip): the driver's business trips, and any
 * private ones they choose to log. Private mileage is never the sum of
 * private trips: it comes from the mileage log (§7.22).
 */
final readonly class Trip
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public TripData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** The driver and claimant; null = a former user. */
        public ?int $createdBy = null,
    ) {
    }

    /**
     * "Ballymena → Belfast", or "Ballymena → Belfast → Ballymena" for a return.
     */
    public function journey(): string
    {
        return Journey::label($this->data->fromPlace, $this->data->toPlace, $this->data->isReturn);
    }

    /**
     * The attachment owner this trip's files hang off, as the templates' paperclip expects.
     *
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['trip', $this->id];
    }
}
