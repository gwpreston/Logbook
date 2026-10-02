<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;

/**
 * One derived cost line of an agreement (spec.md §7.32 *Costs*): never
 * stored. The amount is pennies, in the vehicle's currency.
 */
final readonly class FinanceLine
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $amount,
        public FinanceLineKind $kind,
    ) {
    }
}
