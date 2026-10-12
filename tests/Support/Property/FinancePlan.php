<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

/**
 * A generated HP agreement on a vehicle (spec §7.32): the purchase price as
 * its cash price, a deposit, monthly payments from a month after the
 * purchase, and now and then settled early, for less than the payments
 * left, so the end adjustment can go below zero (#387). Amounts are in the
 * vehicle's currency, typed to its minor unit (#390).
 */
final readonly class FinancePlan
{
    public function __construct(
        public string $deposit,
        public int $payments,
        public string $regularPayment,
        public string $apr,
        public ?string $settledOn = null,
        public ?string $settlement = null,
    ) {
    }
}
