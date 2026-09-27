<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Number\Decimal;

/**
 * A vehicle's fill-ups with their derived figures (see FuelEconomy).
 */
final readonly class FuelHistory
{
    /**
     * @param list<FillEconomy> $fills oldest first
     * @param array<string, EconomySummary> $summaries keyed by EnergyKind value, only kinds with fills
     */
    public function __construct(
        public array $fills,
        public array $summaries,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->fills === [];
    }

    /**
     * @return list<FillEconomy> newest first, for lists
     */
    public function newestFirst(): array
    {
        return array_reverse($this->fills);
    }

    public function summary(EnergyKind $kind): ?EconomySummary
    {
        return $this->summaries[$kind->value] ?? null;
    }

    public function latest(): ?FillEconomy
    {
        return $this->fills === [] ? null : $this->fills[array_key_last($this->fills)];
    }

    /**
     * Everything spent on fuel and charging.
     */
    public function totalCost(): string
    {
        $total = '0';
        foreach ($this->summaries as $summary) {
            $total = Decimal::add($total, $summary->totalCost);
        }

        return $total;
    }

    /**
     * Measured fills of one kind, oldest first (for economy charts).
     *
     * @return list<FillEconomy>
     */
    public function measured(EnergyKind $kind): array
    {
        return array_values(array_filter(
            $this->fills,
            static fn (FillEconomy $fill): bool => $fill->segment !== null && $fill->entry->data->fuel->kind() === $kind,
        ));
    }

    /**
     * Fills of one kind, oldest first.
     *
     * @return list<FillEconomy>
     */
    public function ofKind(EnergyKind $kind): array
    {
        return array_values(array_filter(
            $this->fills,
            static fn (FillEconomy $fill): bool => $fill->entry->data->fuel->kind() === $kind,
        ));
    }
}
