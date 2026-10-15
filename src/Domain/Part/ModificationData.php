<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

use DateTimeImmutable;

/**
 * A modification as the owner entered it, validated and in storage units
 * (spec.md §6 Modification). Decimals are canonical strings.
 */
final readonly class ModificationData
{
    public function __construct(
        public string $title,
        public ModificationCategory $category,
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $doneOn,
        /** Kilometres, when the odometer was noted. */
        public ?string $odometerKm = null,
        /** Canonical decimal; 0 is valid. A cost above 0 is a `build` ledger line. */
        public string $cost = '0',
        /** A garage, or "Self". */
        public ?string $fittedBy = null,
        public ?string $notes = null,
        /** When the insurer was told (read by Phase 50.2). */
        public ?DateTimeImmutable $declaredOn = null,
    ) {
    }
}
