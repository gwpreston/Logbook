<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSet;

/**
 * Everything the Tyres tab shows (spec.md §7.17), derived on every read.
 */
final readonly class TyreOverview
{
    /**
     * @param array<string, ?TyreView> $fitted position code → tyre, in the vehicle type's position order
     * @param list<TyreSetGroup> $stored by set name, tyres not in a set last
     * @param list<TyreView> $retired newest first
     * @param list<TyreChangeView> $changes newest first
     * @param list<TyreSet> $sets every set of the vehicle
     */
    public function __construct(
        public array $fitted,
        public array $stored,
        public array $retired,
        public array $changes,
        public array $sets,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function hasFitted(): bool
    {
        return array_filter($this->fitted) !== [];
    }

    /**
     * @return list<TyreView> the fitted tyres in position order
     */
    public function fittedTyres(): array
    {
        return array_values(array_filter($this->fitted));
    }

    public function at(TyrePosition $position): ?TyreView
    {
        return $this->fitted[$position->value] ?? null;
    }

    /**
     * Whether any tyre's distance has a negative segment.
     */
    public function isFlagged(): bool
    {
        foreach ([...$this->fittedTyres(), ...$this->retired] as $view) {
            if ($view->distance->flagged) {
                return true;
            }
        }
        foreach ($this->stored as $group) {
            foreach ($group->tyres as $view) {
                if ($view->distance->flagged) {
                    return true;
                }
            }
        }

        return false;
    }
}
