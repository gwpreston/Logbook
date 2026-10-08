<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

use DateTimeImmutable;

/**
 * A vehicle's MOT history columns (spec.md §6 Vehicle, Phase 41): when its
 * owner confirmed fetching, the last fetch, the recall state then, and
 * DVSA's first MOT due date for a vehicle with no tests.
 */
final readonly class MotVehicleState
{
    public function __construct(
        public ?DateTimeImmutable $enabledAt = null,
        public ?DateTimeImmutable $fetchedAt = null,
        public ?RecallState $recall = null,
        public ?DateTimeImmutable $firstDueOn = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabledAt !== null;
    }
}
