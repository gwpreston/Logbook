<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Support\Money\Money;

/**
 * One currency's line of the Reports page's *Incidents* section.
 */
final readonly class IncidentReportLine
{
    public function __construct(
        public string $currency,
        /** Incidents dated in the period. */
        public int $count,
        /** The period's costs linked to an incident (already counted in their own groups). */
        public Money $linked,
        /** Payouts on the period's incidents. */
        public Money $payouts,
    ) {
    }
}
