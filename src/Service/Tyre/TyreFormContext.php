<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyrePosition;

/**
 * What a tyre change form may choose from (spec.md §7.17): the vehicle's
 * positions, its tyres as they are, its sets and the service records it may
 * link. Built by the Action; the form parser only checks against it.
 */
final readonly class TyreFormContext
{
    /**
     * @param list<TyrePosition> $positions the vehicle type's positions
     * @param array<string, Tyre> $fitted position code → fitted tyre, in position order
     * @param list<Tyre> $stored
     * @param list<int> $setIds
     * @param list<int> $linkIds service records the change may link
     * @param DateTimeImmutable $today the owner's calendar date
     * @param bool $maintenance whether the maintenance module is on (cost, garage and link fields)
     */
    public function __construct(
        public array $positions,
        public array $fitted,
        public array $stored,
        public array $setIds,
        public array $linkIds,
        public DateTimeImmutable $today,
        public bool $maintenance,
    ) {
    }

    /**
     * @return list<TyrePosition> positions a road tyre can take (not the spare)
     */
    public function rollingPositions(): array
    {
        return array_values(array_filter($this->positions, static fn (TyrePosition $p): bool => $p->isRolling()));
    }
}
