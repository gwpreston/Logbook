<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Support\Number\Decimal;

/**
 * The economy check of the segment one fill-up closes (spec.md §7.3).
 * Consumption and baseline are canonical: litres (or kWh) per 100 km.
 */
final readonly class SegmentCheck
{
    public function __construct(
        /** The closing fill-up, with its segment. */
        public FillEconomy $fill,
        public EconomyVerdict $verdict,
        /** This segment's consumption, 6 places. */
        public string $consumption,
        /** Median of the earlier checkable segments (7 places); null when not checked. */
        public ?string $baseline = null,
        /** Consumption ÷ baseline, 6 places; null when not checked. */
        public ?string $ratio = null,
        /** The owner confirmed exactly this figure: a flag is hidden. */
        public bool $confirmed = false,
        /** The fill-up this segment shares with the opposite flag next to it. */
        public ?FuelEntry $pairShared = null,
        /** The pair taken together is inside the band. */
        public bool $pairNormal = false,
    ) {
    }

    public function withPair(FuelEntry $shared, bool $normalTogether): self
    {
        return new self(
            $this->fill,
            $this->verdict,
            $this->consumption,
            $this->baseline,
            $this->ratio,
            $this->confirmed,
            $shared,
            $normalTogether,
        );
    }

    public function entry(): FuelEntry
    {
        return $this->fill->entry;
    }

    /**
     * A flag to show: more or less than usual, and not confirmed.
     */
    public function isFlagged(): bool
    {
        return $this->verdict->isFlag() && !$this->confirmed;
    }

    /**
     * A flag the owner confirmed as right (shown as a small "checked" mark).
     */
    public function isConfirmedFlag(): bool
    {
        return $this->verdict->isFlag() && $this->confirmed;
    }

    /**
     * How far from usual, as a fraction of fuel used (0.32 = 32% more or less).
     */
    public function difference(): ?float
    {
        return $this->ratio === null ? null : abs((float) Decimal::subtract($this->ratio, '1'));
    }

    /**
     * The other fill-up to check: the shared one of a pair when that is not
     * this fill-up, else the segment's opening fill-up.
     */
    public function otherEntry(): ?FuelEntry
    {
        if ($this->pairShared !== null && $this->pairShared->id !== $this->entry()->id) {
            return $this->pairShared;
        }

        return $this->fill->segment?->opening;
    }
}
