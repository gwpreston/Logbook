<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

use DateTimeImmutable;

/**
 * A user's repeat journey (spec.md §6 SavedJourney). It belongs to the
 * user, not a vehicle; deleting it leaves the trips logged from it.
 */
final readonly class SavedJourney
{
    public function __construct(
        public int $id,
        public int $userId,
        public SavedJourneyData $data,
        public int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function journey(): string
    {
        return Journey::label($this->data->fromPlace, $this->data->toPlace, $this->data->isReturnDefault);
    }
}
