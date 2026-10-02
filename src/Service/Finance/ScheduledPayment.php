<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;

/**
 * One payment of an agreement's schedule, derived on every read (spec.md
 * §7.32 *Schedule*). The amount is a canonical decimal in the vehicle's
 * currency.
 */
final readonly class ScheduledPayment
{
    public function __construct(
        /** 0 for a lease's initial rental, 1… for the regular payments, n + 1 for the final one. */
        public int $number,
        public PaymentKind $kind,
        public DateTimeImmutable $dueOn,
        public string $amount,
        public PaymentStatus $status,
        /** When a paid-late payment was made, if recorded. */
        public ?DateTimeImmutable $paidOn = null,
    ) {
    }

    public function isRegular(): bool
    {
        return $this->kind === PaymentKind::Regular;
    }
}
