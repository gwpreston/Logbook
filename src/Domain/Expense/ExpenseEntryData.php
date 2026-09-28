<?php

declare(strict_types=1);

namespace Logbook\Domain\Expense;

use DateTimeImmutable;

/**
 * An ad-hoc expense as entered and validated. The amount is a canonical
 * decimal in the vehicle's currency.
 */
final readonly class ExpenseEntryData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $spentOn,
        public ExpenseCategory $category,
        /** 0 is valid (a free car park is still worth noting). */
        public string $amount = '0.000',
        /** What it was: "Dartford Crossing", "Airport parking". */
        public ?string $note = null,
    ) {
    }
}
