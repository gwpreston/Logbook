<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\PaymentEvent;

/**
 * An agreement's payments in date order, with the payments made outside the
 * schedule (spec.md §7.32 *Schedule*).
 */
final readonly class PaymentSchedule
{
    /**
     * @param list<ScheduledPayment> $payments in date order; after a settlement, only those up to it
     * @param list<PaymentEvent> $extras extra payments, by date
     */
    public function __construct(
        public array $payments,
        public array $extras,
        public ?PaymentEvent $settlement,
        /** The number of regular payments the agreement has. */
        public int $numberOfPayments,
    ) {
    }

    /** Regular payments made (paid or paid late). */
    public function made(): int
    {
        return count(array_filter(
            $this->payments,
            static fn (ScheduledPayment $p): bool => $p->isRegular() && $p->status->isPaid(),
        ));
    }

    /** Regular payments still to make (due or missed): "18 of 48 remaining". */
    public function remaining(): int
    {
        return count(array_filter(
            $this->payments,
            static fn (ScheduledPayment $p): bool => $p->isRegular() && !$p->status->isPaid(),
        ));
    }

    public function final(): ?ScheduledPayment
    {
        foreach ($this->payments as $payment) {
            if ($payment->kind === PaymentKind::Final) {
                return $payment;
            }
        }

        return null;
    }

    /** The next payment still due, or null. */
    public function next(): ?ScheduledPayment
    {
        foreach ($this->payments as $payment) {
            if ($payment->status === PaymentStatus::Due) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @return list<ScheduledPayment> missed, with no later payment recorded
     */
    public function missed(): array
    {
        return array_values(array_filter(
            $this->payments,
            static fn (ScheduledPayment $p): bool => $p->status === PaymentStatus::Missed,
        ));
    }

    /**
     * @return list<ScheduledPayment> paid or paid late
     */
    public function paid(): array
    {
        return array_values(array_filter($this->payments, static fn (ScheduledPayment $p): bool => $p->status->isPaid()));
    }

    /**
     * @return list<ScheduledPayment> due or missed
     */
    public function owed(): array
    {
        return array_values(array_filter($this->payments, static fn (ScheduledPayment $p): bool => !$p->status->isPaid()));
    }

    /** The last scheduled payment's date: the agreement's end. */
    public function endsOn(): ?DateTimeImmutable
    {
        $last = $this->payments === [] ? null : $this->payments[array_key_last($this->payments)];

        return $last?->dueOn;
    }

    public function first(): ?ScheduledPayment
    {
        return $this->payments[0] ?? null;
    }
}
