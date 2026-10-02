<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

use DateTimeImmutable;

/**
 * The insurance claim on an incident (spec.md §6 Incident *Claim*).
 * Amounts are canonical decimals in the vehicle's currency; 0 is valid.
 */
final readonly class Claim
{
    public function __construct(
        public ClaimStatus $status = ClaimStatus::NotClaimed,
        public ?string $insurer = null,
        /** The `insurance` document the claim is made on. */
        public ?int $insuranceDocumentId = null,
        public ?string $claimNumber = null,
        public ?string $excess = null,
        /** Money the owner received. */
        public ?string $payout = null,
        public NcdEffect $ncdAffected = NcdEffect::Unknown,
        /** Calendar date of the latest news. */
        public ?DateTimeImmutable $updatedOn = null,
        /** What a repair may cost (Phase 27.2): information only, never counted. */
        public ?string $repairEstimate = null,
    ) {
    }
}
