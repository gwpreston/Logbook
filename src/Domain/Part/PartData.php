<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

use DateTimeImmutable;

/**
 * A part as the owner entered it, validated and in storage units (spec.md
 * §6 Part). Decimals are canonical strings.
 */
final readonly class PartData
{
    public function __construct(
        public string $name,
        public ?string $brand = null,
        public ?string $partNumber = null,
        public ?string $serialNumber = null,
        public ?string $supplier = null,
        /** Calendar date. */
        public ?DateTimeImmutable $boughtOn = null,
        /** For reference and a warranty claim; never a ledger line (#417). */
        public ?string $price = null,
        public PartCondition $condition = PartCondition::New,
        public PartOrigin $origin = PartOrigin::Fitted,
        public ?int $warrantyMonths = null,
        /** Kilometres. */
        public ?string $warrantyKm = null,
        /** Set whenever a warranty limit is. */
        public ?WarrantyStart $warrantyFrom = null,
        public ?string $warrantyProvider = null,
        public ?string $warrantyTerms = null,
        public ?string $notes = null,
    ) {
    }

    public function hasWarranty(): bool
    {
        return $this->warrantyMonths !== null || $this->warrantyKm !== null;
    }
}
