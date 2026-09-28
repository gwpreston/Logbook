<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Support\Number\Decimal;

/**
 * One vehicle's economy for one kind of energy over the last 12 months
 * (weighted: measured distance over measured volume).
 */
final readonly class VehicleEfficiency
{
    /**
     * @param list<FillEconomy> $measured the measured fills in the window, oldest first
     */
    public function __construct(
        public Vehicle $vehicle,
        public EnergyKind $kind,
        public array $measured,
    ) {
    }

    public function isElectric(): bool
    {
        return $this->kind === EnergyKind::Electric;
    }

    public function hasEconomy(): bool
    {
        return $this->measured !== [] && Decimal::compare($this->distanceKm(), '0') > 0;
    }

    public function distanceKm(): string
    {
        $total = '0';
        foreach ($this->measured as $fill) {
            $total = Decimal::add($total, $fill->segment->distanceKm ?? '0');
        }

        return $total;
    }

    public function volume(): string
    {
        $total = '0';
        foreach ($this->measured as $fill) {
            $total = Decimal::add($total, $fill->segment->volume ?? '0');
        }

        return $total;
    }

    public function last(): ?FillEconomy
    {
        return $this->measured === [] ? null : $this->measured[array_key_last($this->measured)];
    }
}
