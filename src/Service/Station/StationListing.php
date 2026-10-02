<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Station;

/**
 * One row of the stations list or the combo box (spec.md §7.33): the
 * station, whether the user favoured it, their figures there over all
 * time and over the last 12 months (null when none).
 */
final readonly class StationListing
{
    public function __construct(
        public Station $station,
        public bool $favourite,
        public ?StationSummary $summary,
        public ?StationSummary $lastYear,
    ) {
    }
}
