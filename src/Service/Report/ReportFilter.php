<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * What a report covers (spec.md §7.7): a period, the fleet or one vehicle,
 * and whether archived vehicles count towards the fleet.
 */
final readonly class ReportFilter
{
    public function __construct(
        public ReportPeriod $period,
        public ?int $vehicleId = null,
        public bool $includeArchived = false,
        /** Only this cost group's lines (Phase 26.2), or every group. */
        public ?CostGroup $group = null,
    ) {
    }

    /**
     * From the report form's GET parameters (`range`, `from`, `to`,
     * `vehicle`, `include_archived`, `group`).
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, DateTimeImmutable $today): self
    {
        $vehicle = $query['vehicle'] ?? null;
        $group = $query['group'] ?? null;

        return new self(
            ReportPeriod::fromQuery($query, $today),
            is_string($vehicle) && ctype_digit($vehicle) && (int) $vehicle > 0 ? (int) $vehicle : null,
            ($query['include_archived'] ?? null) === '1',
            is_string($group) ? CostGroup::tryFrom($group) : null,
        );
    }

    /**
     * The vehicles the report is about: the one asked for (archived or
     * not: picking it is explicit), else the active fleet plus, when ticked,
     * the archived vehicles. An unknown vehicle means the fleet.
     *
     * @param list<Vehicle> $vehicles all of the owner's vehicles
     * @return list<Vehicle>
     */
    public function scope(array $vehicles): array
    {
        if ($this->vehicleId !== null) {
            foreach ($vehicles as $vehicle) {
                if ($vehicle->id === $this->vehicleId) {
                    return [$vehicle];
                }
            }
        }

        return array_values(array_filter(
            $vehicles,
            fn (Vehicle $vehicle): bool => $this->includeArchived || !$vehicle->isArchived(),
        ));
    }

    /**
     * For links and forms: the query that selects this report again.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this->period->toQuery()
            + ($this->vehicleId !== null ? ['vehicle' => (string) $this->vehicleId] : [])
            + ($this->includeArchived ? ['include_archived' => '1'] : [])
            + ($this->group !== null ? ['group' => $this->group->value] : []);
    }
}
