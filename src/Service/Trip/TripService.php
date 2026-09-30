<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeZone;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\SavedJourneyRepository;
use Logbook\Repository\TripRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Trips (spec.md §7.22) with their attachments. A trip writes no odometer
 * reading. A user sees their own trips, and other drivers' only with
 * `ViewOthersTrips` (Manage and Own): destinations are personal.
 */
final readonly class TripService
{
    public function __construct(
        private TripRepository $trips,
        private SavedJourneyRepository $journeys,
        private OdometerReadingRepository $readings,
        private AttachmentService $attachments,
        private VehicleAccess $access,
        private AccessContext $author,
        private ClockInterface $clock,
    ) {
    }

    public function seesEveryone(User $user, Vehicle $vehicle): bool
    {
        return $this->access->can($user, VehicleAbility::ViewOthersTrips, $vehicle);
    }

    /**
     * The trips this user may see on the vehicle, newest first.
     *
     * @return list<Trip>
     */
    public function visible(User $user, Vehicle $vehicle): array
    {
        return $this->trips->listForVehicle($vehicle->id, $this->seesEveryone($user, $vehicle) ? null : $user->id);
    }

    /**
     * @throws TripNotFound when there is none, or it is another driver's and this user may not see it
     */
    public function get(User $user, Vehicle $vehicle, int $id): Trip
    {
        $trip = $this->trips->find($vehicle->id, $id);
        if ($trip === null || !$this->canSee($user, $vehicle, $trip)) {
            throw new TripNotFound(sprintf('Trip %d not found.', $id));
        }

        return $trip;
    }

    public function canSee(User $user, Vehicle $vehicle, Trip $trip): bool
    {
        return $trip->createdBy === $user->id || $this->seesEveryone($user, $vehicle);
    }

    /**
     * @param bool $saveJourney also keep it as one of the author's saved journeys
     */
    public function create(
        Vehicle $vehicle,
        TripData $data,
        PendingUploads $files = new PendingUploads(),
        bool $saveJourney = false,
    ): Trip {
        $by = $this->author->authorId() ?? $vehicle->userId;
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data, $by): int {
            $id = $this->trips->insert($vehicle->id, $data, $this->clock->now(), $by);
            $this->attachments->record($vehicle, AttachmentOwner::Trip, $id, $stored);

            return $id;
        });
        if ($saveJourney) {
            $this->saveAsJourney($by, $data);
        }

        return $this->trips->find($vehicle->id, $id) ?? throw new TripNotFound('Trip not saved.');
    }

    public function update(
        Vehicle $vehicle,
        Trip $trip,
        TripData $data,
        PendingUploads $files = new PendingUploads(),
        bool $saveJourney = false,
    ): Trip {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $trip, $data): void {
            $this->trips->update($vehicle->id, $trip->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Trip, $trip->id, $stored);
        });
        if ($saveJourney) {
            $this->saveAsJourney($this->author->authorId() ?? $trip->createdBy ?? $vehicle->userId, $data);
        }

        return $this->trips->find($vehicle->id, $trip->id) ?? throw new TripNotFound('Trip not saved.');
    }

    public function delete(Vehicle $vehicle, Trip $trip): void
    {
        $this->trips->delete($vehicle->id, $trip->id);
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Trip, $trip->id);
    }

    /**
     * The most the vehicle can have driven on the trip's day, when readings
     * exist on both sides of it (the gap between the last reading before the
     * day and the first after it), if the trip is longer. Never blocking
     * (spec.md §7.22 *Warning*).
     *
     * @return string|null kilometres driven that day at most, when the trip is longer
     */
    public function longerThanDriven(Vehicle $vehicle, TripData $data, DateTimeZone $zone): ?string
    {
        $dayStart = LocalTime::toUtc($data->travelledOn->format('Y-m-d') . 'T00:00', $zone);
        $dayEnd = LocalTime::toUtc($data->travelledOn->modify('+1 day')->format('Y-m-d') . 'T00:00', $zone);
        if ($dayStart === null || $dayEnd === null) {
            return null;
        }

        $before = null;
        $after = null;
        foreach ($this->readings->listForVehicle($vehicle->id) as $reading) {
            if ($reading->recordedAt < $dayStart) {
                $before = $reading;
            } elseif ($reading->recordedAt >= $dayEnd && $after === null) {
                $after = $reading;
            }
        }
        if ($before === null || $after === null) {
            return null;
        }

        $driven = Decimal::subtract($after->readingKm, $before->readingKm);

        return Decimal::compare($data->distanceKm, $driven) > 0 ? $driven : null;
    }

    private function saveAsJourney(int $userId, TripData $data): void
    {
        $oneWay = $data->isReturn ? Decimal::divide($data->distanceKm, '2', TripRepository::DISTANCE_SCALE) : $data->distanceKm;

        $this->journeys->insert($userId, new SavedJourneyData(
            fromPlace: $data->fromPlace,
            toPlace: $data->toPlace,
            distanceKm: $oneWay,
            isReturnDefault: $data->isReturn,
            purposeDefault: $data->purpose,
            isBusinessDefault: $data->isBusiness,
        ), $this->clock->now());
    }
}
