<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * The car's value against what settling would cost (spec.md §7.32
 * *Equity*).
 */
final readonly class Equity
{
    public function __construct(
        public Money $valuation,
        public DateTimeImmutable $valuedOn,
        /** Valuation − settlement: negative when more is owed than the car is worth. */
        public Money $amount,
    ) {
    }

    public function isPositive(): bool
    {
        return !$this->amount->isNegative();
    }

    /** The amount without its sign, for "Negative equity £1,380". */
    public function magnitude(): Money
    {
        return $this->amount->isNegative() ? Money::zero($this->amount->currency)->subtract($this->amount) : $this->amount;
    }
}
