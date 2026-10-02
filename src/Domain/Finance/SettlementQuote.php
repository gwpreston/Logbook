<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

use DateTimeImmutable;

/**
 * The lender's own settlement figure (spec.md §6 SettlementQuote): it
 * replaces Logbook's estimate while valid.
 */
final readonly class SettlementQuote
{
    public function __construct(
        public int $id,
        public int $agreementId,
        public DateTimeImmutable $quotedOn,
        public string $amount,
        public DateTimeImmutable $validUntil,
        public ?string $notes,
    ) {
    }

    public function isValidOn(DateTimeImmutable $today): bool
    {
        return $this->quotedOn <= $today && $today <= $this->validUntil;
    }
}
