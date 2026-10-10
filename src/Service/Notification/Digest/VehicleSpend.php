<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use Logbook\Service\Expense\CostItem;
use Logbook\Support\Money\Money;

/**
 * One vehicle's money for last month in the digest (spec.md §7.11 *The
 * monthly briefing*), only for a viewer with `ViewCosts`: what the Reports
 * page shows for that month, with the averages to compare against.
 */
final readonly class VehicleSpend
{
    public function __construct(
        public string $currency,
        /** The month's ledger total. */
        public Money $spend,
        /** The monthly average of the 12 months before, or null with fewer than 3 months in use (#364). */
        public ?Money $average,
        /** Money per kilometre (canonical decimal), null under 100 km. */
        public ?string $costPerKm,
        /** The 12 months' spend ÷ their distance, null under 100 km. */
        public ?string $costPerKmAverage,
        /** The one ledger line that is more than half of the month's spend, if any. */
        public ?CostItem $largest,
    ) {
    }
}
