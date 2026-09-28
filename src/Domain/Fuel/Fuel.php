<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Domain\Vehicle\FuelType;

/**
 * What went into the vehicle at one fill-up. Codes match the vehicle fuel
 * types, except that there is no "hybrid" fuel: a hybrid fills with petrol
 * (or, if it plugs in, is charged with electricity).
 *
 * Electricity is measured in kWh instead of litres; everything else about an
 * entry has the same shape.
 */
enum Fuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Lpg = 'lpg';
    case Electricity = 'ev';
    case Other = 'other';

    /**
     * The usual fuel for a vehicle: its own fuel type, petrol for a hybrid.
     */
    public static function defaultFor(FuelType $type): self
    {
        return match ($type) {
            FuelType::Hybrid => self::Petrol,
            default => self::from($type->value),
        };
    }

    public function isElectric(): bool
    {
        return $this === self::Electricity;
    }

    /**
     * The pump / charger label shape (spec.md §8): EN 16942 circle for
     * petrol, square for diesel, rhombus for LPG, EN 17186 hexagon for
     * charging; none for other fuels.
     */
    public function badgeShape(): ?string
    {
        return match ($this) {
            self::Petrol => 'circle',
            self::Diesel => 'square',
            self::Lpg => 'rhombus',
            self::Electricity => 'hexagon',
            self::Other => null,
        };
    }

    /**
     * Economy is only ever computed between entries of the same kind:
     * litres and kWh cannot be added up.
     */
    public function kind(): EnergyKind
    {
        return $this->isElectric() ? EnergyKind::Electric : EnergyKind::Liquid;
    }
}
