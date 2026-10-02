<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

/**
 * What a payment event records (spec.md §6 FinancePaymentEvent): an
 * exception to a scheduled payment, or a payment outside the schedule.
 */
enum PaymentEventKind: string
{
    /** A scheduled payment that wasn't made. */
    case Missed = 'missed';
    /** A missed payment made later. */
    case PaidLate = 'paid_late';
    /** A payment on top of the schedule. */
    case Extra = 'extra';
    /** The amount that settled the agreement early. */
    case Settlement = 'settlement';

    public function labelKey(): string
    {
        return 'finance.event.' . $this->value;
    }

    /** Extra and settlement payments carry their own amount and date. */
    public function isPayment(): bool
    {
        return $this === self::Extra || $this === self::Settlement;
    }
}
