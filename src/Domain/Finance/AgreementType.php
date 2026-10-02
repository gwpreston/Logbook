<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

/**
 * The kind of finance agreement (spec.md §6 FinanceAgreement, §7.32): what
 * its paperwork gives and which figures apply.
 */
enum AgreementType: string
{
    case Hp = 'hp';
    case Pcp = 'pcp';
    case Loan = 'loan';
    case Lease = 'lease';

    public function labelKey(): string
    {
        return 'finance.type.' . $this->value;
    }

    /** HP and PCP finance the car itself, at a cash price. */
    public function hasCashPrice(): bool
    {
        return $this === self::Hp || $this === self::Pcp;
    }

    /** Everything but a lease lends money: settlement, cost of credit, equity. */
    public function isCredit(): bool
    {
        return $this !== self::Lease;
    }

    /** PCP and leases come with a mileage allowance. */
    public function hasMileage(): bool
    {
        return $this === self::Pcp || $this === self::Lease;
    }

    /** HP and PCP reach a half-paid point (spec.md §7.32 *Half-paid point*). */
    public function hasHalfPaidPoint(): bool
    {
        return $this->hasCashPrice();
    }

    /** A PCP's final payment is optional: shown beside what remains, not inside it. */
    public function finalPaymentIsOptional(): bool
    {
        return $this === self::Pcp;
    }
}
