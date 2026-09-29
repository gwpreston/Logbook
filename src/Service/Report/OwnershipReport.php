<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * The ownership report (spec.md §7.7 *Cost of ownership*): one section per
 * currency, most vehicles first.
 */
final readonly class OwnershipReport
{
    /**
     * @param list<Vehicle> $vehicles the vehicles in scope (some may have no ownership period)
     * @param list<OwnershipSection> $sections
     */
    public function __construct(
        public ReportFilter $filter,
        public array $vehicles,
        public array $sections,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    public function hasSeveralCurrencies(): bool
    {
        return count($this->sections) > 1;
    }

    /**
     * Every row, section by section, for the CSV.
     *
     * @return list<OwnershipCost>
     */
    public function rows(): array
    {
        return array_merge(...array_map(static fn (OwnershipSection $s): array => $s->rows, $this->sections));
    }
}
