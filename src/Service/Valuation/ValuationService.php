<?php

declare(strict_types=1);

namespace Logbook\Service\Valuation;

use Logbook\Service\Access\AccessContext;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ValuationRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUploads;
use Psr\Clock\ClockInterface;

/**
 * Valuations (spec.md §7.1), with their attachments (the screenshot of a
 * quote): saved with the valuation, deleted with it. Callers pass a Vehicle
 * already resolved for the signed-in owner.
 */
final readonly class ValuationService
{
    public function __construct(
        private ValuationRepository $valuations,
        private AttachmentService $attachments,
        private ClockInterface $clock,
        private AccessContext $author,
    ) {
    }

    /**
     * @return list<VehicleValuation> oldest first
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        return $this->valuations->listForVehicle($vehicle->id);
    }

    /**
     * @throws ValuationNotFound
     */
    public function get(Vehicle $vehicle, int $id): VehicleValuation
    {
        return $this->valuations->find($vehicle->id, $id)
            ?? throw new ValuationNotFound(sprintf('Valuation %d not found.', $id));
    }

    public function create(
        Vehicle $vehicle,
        VehicleValuationData $data,
        PendingUploads $files = new PendingUploads(),
    ): VehicleValuation {
        $id = $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $data): int {
            $by = $this->author->authorId() ?? $vehicle->userId;
            $id = $this->valuations->insert($vehicle->id, $data, $this->clock->now(), $by);
            $this->attachments->record($vehicle, AttachmentOwner::Valuation, $id, $stored);

            return $id;
        });

        return $this->get($vehicle, $id);
    }

    public function update(
        Vehicle $vehicle,
        VehicleValuation $valuation,
        VehicleValuationData $data,
        PendingUploads $files = new PendingUploads(),
    ): VehicleValuation {
        $this->attachments->saveWithFiles($files, function (array $stored) use ($vehicle, $valuation, $data): void {
            $this->valuations->update($vehicle->id, $valuation->id, $data, $this->clock->now());
            $this->attachments->record($vehicle, AttachmentOwner::Valuation, $valuation->id, $stored);
        });

        return $this->get($vehicle, $valuation->id);
    }

    /**
     * Delete a valuation with its attachments.
     */
    public function delete(Vehicle $vehicle, VehicleValuation $valuation): void
    {
        $this->valuations->delete($vehicle->id, $valuation->id);
        $this->attachments->deleteForOwner($vehicle, AttachmentOwner::Valuation, $valuation->id);
    }
}
