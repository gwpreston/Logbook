<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * When the deposit and payments reach half the total amount payable (spec.md
 * §7.32 *Half-paid point*). A figure, never a recommendation.
 */
final readonly class HalfPaidPoint
{
    public function __construct(
        /** Half the total amount payable. */
        public Money $target,
        public Money $paidSoFar,
        /** Still needed to reach it; zero once reached. */
        public Money $stillNeeded,
        /** The payment date it is (or will be) reached on; null if the schedule never reaches it. */
        public ?DateTimeImmutable $on,
        public bool $reached,
    ) {
    }
}
