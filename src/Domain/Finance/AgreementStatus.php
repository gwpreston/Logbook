<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

/**
 * Where an agreement stands (spec.md §6 FinanceAgreement). A vehicle has at
 * most one `active` agreement; the others are kept as history.
 */
enum AgreementStatus: string
{
    case Active = 'active';
    case Settled = 'settled';
    case Completed = 'completed';
    case HandedBack = 'handed_back';
    case Ended = 'ended';

    public function labelKey(): string
    {
        return 'finance.status.' . $this->value;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
