<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Maintenance\MaintenanceEntry;

/**
 * A maintenance record far above its category's usual (CostOutlier).
 */
final readonly class CostFinding
{
    public function __construct(
        public MaintenanceEntry $entry,
        /** Median cost of the category's earlier records. */
        public string $median,
        /** Its cost ÷ the median, 6 places. */
        public string $ratio,
        /** Within ×/÷ 1.25 of a power of ten: "an extra digit?". */
        public bool $digitSlip,
    ) {
    }
}
