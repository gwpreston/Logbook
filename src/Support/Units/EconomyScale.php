<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

use Logbook\Domain\Fuel\EnergyKind;

/**
 * The unit one kind of energy's economy is shown in for a user: their
 * consumption unit for liquid fuel, kWh/100 km or mi/kWh for electricity,
 * kg/100 km or mi/kg for CNG (Phase 31). Charts and figures that work with
 * numbers rather than formatted text use it, so they never branch on the
 * kind themselves.
 */
final readonly class EconomyScale
{
    private function __construct(
        private EnergyKind $kind,
        private ConsumptionUnit|ElectricEfficiencyUnit|GasEfficiencyUnit $unit,
        private VolumeUnit $volumeUnit,
    ) {
    }

    public static function of(EnergyKind $kind, DistanceUnit $distance, ConsumptionUnit $consumption, VolumeUnit $volume): self
    {
        return new self($kind, match ($kind) {
            EnergyKind::Liquid => $consumption,
            EnergyKind::Electric => ElectricEfficiencyUnit::forDistanceUnit($distance),
            EnergyKind::Gas => GasEfficiencyUnit::forDistanceUnit($distance),
        }, $volume);
    }

    /**
     * The unit's code, for `units.name.<code>` and API output.
     */
    public function code(): string
    {
        return $this->unit->value;
    }

    /**
     * Economy over a distance in this unit, or null when either side is not
     * positive.
     *
     * @param float $volume litres, kWh or kg, as stored
     */
    public function value(float $km, float $volume): ?float
    {
        return match (true) {
            $this->unit instanceof ConsumptionUnit => $this->unit->fromDistanceAndVolume($km, $volume),
            $this->unit instanceof ElectricEfficiencyUnit => $this->unit->fromDistanceAndEnergy($km, $volume),
            $this->unit instanceof GasEfficiencyUnit => $this->unit->fromDistanceAndMass($km, $volume),
        };
    }

    public function higherIsBetter(): bool
    {
        return $this->unit->higherIsBetter();
    }

    /**
     * The code of the unit quantities are shown in, for `units.symbol.<code>`:
     * the user's volume unit, `kwh` or `kg`.
     */
    public function quantityCode(): string
    {
        return match ($this->kind) {
            EnergyKind::Liquid => $this->volumeUnit->value,
            EnergyKind::Electric => 'kwh',
            EnergyKind::Gas => 'kg',
        };
    }

    /**
     * A stored price per litre (or kWh, or kg) as a price per the unit
     * quantities are shown in.
     */
    public function pricePerShownUnit(float $perStoredUnit): float
    {
        return $this->kind->followsVolumeUnit() ? $perStoredUnit * $this->volumeUnit->litresPerUnit() : $perStoredUnit;
    }
}
