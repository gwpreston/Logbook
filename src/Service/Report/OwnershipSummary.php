<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Support\Money\Money;

/**
 * The four summary cards of the Cost of ownership page for one currency
 * (spec.md §7.7 *Cost of ownership page*), adding up exactly the vehicle
 * cards under them. Pure: no database; amounts are never converted.
 */
final readonly class OwnershipSummary
{
    /**
     * @param list<OwnershipCard> $cards highest total first
     */
    private function __construct(
        public string $currency,
        public array $cards,
        /** Every card's total (the running costs of one without depreciation). */
        public Money $total,
        /** Cards with depreciation, so with a full total. */
        public int $completeCount,
        /** The active vehicles' own per month figures added up (#187); null with none. */
        public ?Money $perMonth,
        /** The depreciation of the cards that have one; null with none (a net gain is negative). */
        public ?Money $depreciation,
        /** The credit charges counted (#186); null without finance. */
        public ?Money $financeCharges,
    ) {
    }

    /**
     * @param list<OwnershipCard> $cards all in $currency
     */
    public static function of(string $currency, array $cards): self
    {
        usort($cards, static fn (OwnershipCard $a, OwnershipCard $b): int
            => ($b->total()->micros <=> $a->total()->micros) ?: strcmp($a->vehicle()->name(), $b->vehicle()->name()));

        $total = Money::zero($currency);
        $complete = 0;
        $perMonth = null;
        $depreciation = null;
        $charges = null;
        foreach ($cards as $card) {
            $cost = $card->cost();
            $total = $total->add($card->total());
            if ($cost->depreciationCost !== null) {
                $complete++;
                $depreciation = ($depreciation ?? Money::zero($currency))->add($cost->depreciationCost);
            }
            if ($card->isActive() && $cost->perMonth !== null) {
                $perMonth = ($perMonth ?? Money::zero($currency))->add($cost->perMonth);
            }
            if ($cost->financeCharges !== null) {
                $charges = ($charges ?? Money::zero($currency))->add($cost->financeCharges);
            }
        }

        return new self($currency, $cards, $total, $complete, $perMonth, $depreciation, $charges);
    }

    /**
     * Depreciation as a whole-number share of the total, or null when it
     * means nothing (no depreciation, a total of nothing or less).
     */
    public function depreciationShare(): ?int
    {
        if ($this->depreciation === null || $this->total->micros <= 0) {
            return null;
        }

        return (int) round($this->depreciation->micros / $this->total->micros * 100);
    }
}
