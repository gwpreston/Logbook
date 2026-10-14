<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;

/**
 * One payment's split into interest and capital (spec.md §7.32 *Cost of
 * credit*), as AgreementFigures::interest() works it out: the balance
 * before it, the interest accrued since the previous payment, and the
 * balance after. Kept so the working (§8) quotes the same numbers.
 */
final readonly class InterestStep
{
    public function __construct(
        public int $number,
        public DateTimeImmutable $dueOn,
        /** The payment, pennies. */
        public string $payment,
        /** The balance before the payment, pennies. */
        public string $balanceBefore,
        /** The interest in it, pennies (never more than the payment). */
        public string $interest,
        /** The balance after the payment, pennies. */
        public string $balanceAfter,
    ) {
    }

    /** The part of the payment that reduces the balance. */
    public function capital(): string
    {
        return (string) \Brick\Math\BigDecimal::of($this->payment)->minus($this->interest)->toScale(2);
    }
}
