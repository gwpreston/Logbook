<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

/**
 * Whether a scheduled payment has been made (spec.md §7.32 *Schedule*):
 * paid on its due date unless an event says otherwise.
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Due = 'due';
    case Missed = 'missed';
    case PaidLate = 'paid_late';

    public function labelKey(): string
    {
        return 'finance.payment_status.' . $this->value;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::PaidLate;
    }
}
