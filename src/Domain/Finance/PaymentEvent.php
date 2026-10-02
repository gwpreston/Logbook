<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

use DateTimeImmutable;

/**
 * An exception to the schedule, or a payment outside it (spec.md §6
 * FinancePaymentEvent).
 */
final readonly class PaymentEvent
{
    public function __construct(
        public int $id,
        public int $agreementId,
        public PaymentEventKind $kind,
        /** The scheduled payment it concerns; null for an extra payment. */
        public ?DateTimeImmutable $dueOn,
        /** For extra and settlement payments. */
        public ?string $amount,
        public ?DateTimeImmutable $paidOn,
        public ?string $notes,
    ) {
    }

    /**
     * The date an extra or settlement payment was made: its paid date, else
     * the date it concerns.
     */
    public function paymentDate(): ?DateTimeImmutable
    {
        return $this->paidOn ?? $this->dueOn;
    }
}
