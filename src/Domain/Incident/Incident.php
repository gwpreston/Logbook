<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

use DateTimeImmutable;

/**
 * Something that happened to a vehicle: a scrape, a break-in, a pothole
 * (spec.md §6 Incident, §7.29). It links the records it caused rather than
 * copying their costs.
 */
final readonly class Incident
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public IncidentData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who logged it (Phase 19); null = a former user. */
        public ?int $createdBy = null,
    ) {
    }

    /**
     * The attachment owner this incident's files hang off, as the templates' paperclip expects.
     *
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['incident', $this->id];
    }

    /**
     * The date the claim was last heard of: the latest update, else the
     * incident's date (spec.md §7.24 *Stalled claim*).
     */
    public function lastClaimNews(): DateTimeImmutable
    {
        return $this->data->claim->updatedOn ?? $this->data->occurredOn;
    }
}
