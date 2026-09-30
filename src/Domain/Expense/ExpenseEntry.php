<?php

declare(strict_types=1);

namespace Logbook\Domain\Expense;

use DateTimeImmutable;

/**
 * An ad-hoc cost (spec.md §6 ExpenseEntry): parking, tolls, road tax and the
 * like. Fuel, maintenance and document costs are never copied here.
 */
final readonly class ExpenseEntry
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public ExpenseEntryData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it (Phase 19); null = the vehicle's owner, or a former user. */
        public ?int $createdBy = null,
    ) {
    }
}
