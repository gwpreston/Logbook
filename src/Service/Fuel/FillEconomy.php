<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\FuelEntry;

/**
 * One fill-up with its derived figures, recomputed from the whole history on
 * every read (nothing derived is stored, so edits can never leave it stale).
 */
final readonly class FillEconomy
{
    public function __construct(
        public FuelEntry $entry,
        public EconomyStatus $status,
        /** Kilometres since the previous fill-up of the same kind, if any. */
        public ?string $distanceSincePreviousKm,
        /** Set when status is Measured. */
        public ?EconomySegment $segment,
    ) {
    }

    /**
     * Whether a missed fill-up before this one restarted the measurement.
     */
    public function followsGap(): bool
    {
        return $this->entry->data->isMissedPrevious;
    }
}
