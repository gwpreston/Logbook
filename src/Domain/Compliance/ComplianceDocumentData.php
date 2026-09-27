<?php

declare(strict_types=1);

namespace Logbook\Domain\Compliance;

use DateTimeImmutable;

/**
 * A compliance document as entered, validated and in storage form. Dates are
 * calendar dates (midnight UTC; see Support\Date\LocalTime); the cost is a
 * canonical decimal in the vehicle's currency.
 */
final readonly class ComplianceDocumentData
{
    public function __construct(
        public ComplianceType $type,
        /** Optional name, e.g. "Breakdown cover"; needed to tell "other" documents apart. */
        public ?string $title = null,
        /** Insurer, testing station, authority. */
        public ?string $provider = null,
        /** Policy or certificate number. */
        public ?string $reference = null,
        public ?DateTimeImmutable $startOn = null,
        public ?DateTimeImmutable $expiryOn = null,
        /** 0 is valid. */
        public string $cost = '0.000',
        public ?string $notes = null,
    ) {
    }
}
