<?php

declare(strict_types=1);

namespace Logbook\Domain\Station;

use DateTimeImmutable;
use Logbook\Domain\FuelPrices\StationLink;

/**
 * A fuel station or public charger (spec.md §6 Station, §7.33), shared by
 * every user of the install. A merged station keeps its row and points at
 * the one it became, so old links still resolve.
 */
final readonly class Station
{
    public function __construct(
        public int $id,
        public StationData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it; null = a former user, or nobody (an admin edits it). */
        public ?int $createdBy = null,
        public ?int $mergedInto = null,
        /** Its provider station (Phase 30.2, spec.md §7.34 *Linking stations*). */
        public ?StationLink $link = null,
    ) {
    }

    public function isMerged(): bool
    {
        return $this->mergedInto !== null;
    }
}
