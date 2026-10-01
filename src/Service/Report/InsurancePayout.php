<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * Money an insurer paid the owner for an incident (spec.md §7.29
 * *Ownership*): it lowers what the vehicle has cost, never what was spent.
 * Dated by the incident's date.
 */
final readonly class InsurancePayout
{
    public function __construct(
        /** Calendar date (midnight UTC). */
        public DateTimeImmutable $date,
        public Money $amount,
        public int $incidentId,
    ) {
    }
}
