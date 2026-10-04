<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Domain\Vehicle\FuelType;

/**
 * What went into the vehicle at one fill-up. Codes match the vehicle fuel
 * types, except that there is no "hybrid" or "phev" fuel: a hybrid fills
 * with petrol, and a plug-in hybrid also charges with electricity.
 *
 * Electricity is measured in kWh and CNG in kg instead of litres; everything
 * else about an entry has the same shape.
 */
enum Fuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Lpg = 'lpg';
    /** Compressed natural gas, sold by the kg (Phase 31). */
    case Cng = 'cng';
    case Electricity = 'ev';
    case Other = 'other';

    /**
     * The usual fuel for a vehicle: the first family that fits it (petrol
     * for either kind of hybrid).
     */
    public static function defaultFor(FuelType $type): self
    {
        return $type->fittingFamilies()[0];
    }

    public function isElectric(): bool
    {
        return $this === self::Electricity;
    }

    /**
     * The pump / charger label shape (spec.md §8): EN 16942 circle for
     * petrol, square for diesel, rhombus for LPG and CNG, EN 17186 hexagon
     * for charging; none for other fuels.
     */
    public function badgeShape(): ?string
    {
        return match ($this) {
            self::Petrol => 'circle',
            self::Diesel => 'square',
            self::Lpg, self::Cng => 'rhombus',
            self::Electricity => 'hexagon',
            self::Other => null,
        };
    }

    /**
     * Economy is only ever computed between entries of the same kind:
     * litres, kWh and kg cannot be added up.
     */
    public function kind(): EnergyKind
    {
        return match ($this) {
            self::Electricity => EnergyKind::Electric,
            self::Cng => EnergyKind::Gas,
            default => EnergyKind::Liquid,
        };
    }
}
