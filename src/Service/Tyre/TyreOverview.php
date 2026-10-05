<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Support\Number\Decimal;

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
        /** The tyres judged as one: the tab badge, and what the tyre reminder says. */
        public TyreVerdict $verdict = new TyreVerdict(DueStatus::Unknown),
        /** The owner's replace-at for this vehicle type, mm (a car's non-winter value): the note under the cards. */
        public string $replaceAtMm = TyreThresholds::DEFAULT_CAR_REPLACE_MM,
        /** The owner's legal minimum for this vehicle type, mm. */
        public string $legalMm = TyreThresholds::DEFAULT_CAR_LEGAL_MM,
    ) {
    }

    /**
     * A tyre's card flags, most severe first (Phase 33.3): the pill and the
     * others listed under it.
     *
     * @return list<TyreCardFlag>
     */
    public function flags(TyreView $view): array
    {
        return TyreCardFlag::of($this->verdict->standing($view->tyre->id), $view);
    }

    /**
     * The soonest distance left among the fitted tyres, or null when none
     * is known (the overview card's "about 6,000 mi left").
     */
    public function soonestKmLeft(): ?string
    {
        $soonest = null;
        foreach ($this->fittedTyres() as $view) {
            $left = $view->wear->kmLeft;
            if ($left !== null && ($soonest === null || Decimal::compare($left, $soonest) < 0)) {
                $soonest = $left;
            }
        }

        return $soonest;
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
