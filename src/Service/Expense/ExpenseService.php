<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ExpenseEntryRepository;
use Psr\Clock\ClockInterface;

/**
 * Ad-hoc expenses (spec.md §7.7). Callers pass a Vehicle already resolved for
 * the signed-in owner.
 */
final readonly class ExpenseService
{
    public function __construct(
        private ExpenseEntryRepository $entries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ExpenseEntryNotFound
     */
    public function get(Vehicle $vehicle, int $id): ExpenseEntry
    {
        return $this->entries->find($vehicle->id, $id)
            ?? throw new ExpenseEntryNotFound(sprintf('Expense %d not found.', $id));
    }

    public function create(Vehicle $vehicle, ExpenseEntryData $data): ExpenseEntry
    {
        $id = $this->entries->insert($vehicle->id, $data, $this->clock->now());

        return $this->get($vehicle, $id);
    }

    public function update(Vehicle $vehicle, ExpenseEntry $entry, ExpenseEntryData $data): ExpenseEntry
    {
        $this->entries->update($vehicle->id, $entry->id, $data, $this->clock->now());

        return $this->get($vehicle, $entry->id);
    }

    public function delete(Vehicle $vehicle, ExpenseEntry $entry): void
    {
        $this->entries->delete($vehicle->id, $entry->id);
    }
}
