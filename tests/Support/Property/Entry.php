<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

/**
 * One generated entry. `kind` says which fields matter:
 * - `fill`: `at` (UTC instant), `km`, `volume`, `price`, `amount`, `partial`;
 * - `expense`: `date`, `category`, `amount`;
 * - `maintenance`: `date`, `amount`;
 * - `document`: `date` (start), `expiry`, `amount`;
 * - `valuation`: `date`, `amount`;
 * - `payout`: `date` (the incident), `amount` (the payout), `excess`.
 */
final readonly class Entry
{
    public function __construct(
        public string $kind,
        public string $amount,
        public ?string $date = null,
        public ?string $at = null,
        public ?string $km = null,
        public ?string $volume = null,
        public ?string $price = null,
        public bool $partial = false,
        public ?string $category = null,
        public ?string $expiry = null,
        public ?string $excess = null,
    ) {
    }

    /** The same entry with another amount (for splitting an expense). */
    public function withAmount(string $amount): self
    {
        return new self(
            $this->kind,
            $amount,
            $this->date,
            $this->at,
            $this->km,
            $this->volume,
            $this->price,
            $this->partial,
            $this->category,
            $this->expiry,
            $this->excess,
        );
    }
}
