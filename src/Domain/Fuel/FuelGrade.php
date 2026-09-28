<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Domain\Vehicle\FuelType;

/**
 * Which fuel of a family went in (spec.md §7.3): the petrol grade, the
 * diesel blend or, for electricity, how it was charged. A grade refines
 * Fuel; it never replaces it (economy, units and series follow the family).
 *
 * The codes are stored and exported, so they **never change** once
 * released; labels are translated (`fuel.grade.<code>`, short labels
 * `fuel.grade_short.<code>`) and may be reworded. Adding a grade needs no
 * migration.
 */
enum FuelGrade: string
{
    case E10_95 = 'e10_95';
    case E5_95 = 'e5_95';
    case E5_97 = 'e5_97';
    case E5_98 = 'e5_98';
    case E10_98 = 'e10_98';
    case E5_99 = 'e5_99';
    case E0 = 'e0';
    case E85 = 'e85';
    case E15 = 'e15';
    case E20 = 'e20';
    case Aki87 = 'aki_87';
    case Aki89 = 'aki_89';
    case Aki91 = 'aki_91';

    case B7 = 'b7';
    case B7Premium = 'b7_premium';
    case B10 = 'b10';
    case B20 = 'b20';
    case B100 = 'b100';
    case Xtl = 'xtl';

    case Home = 'home';
    case Ac = 'ac';
    case Dc = 'dc';
    case DcRapid = 'dc_rapid';
    case DcUltra = 'dc_ultra';

    public function family(): Fuel
    {
        return match ($this) {
            self::B7, self::B7Premium, self::B10, self::B20, self::B100, self::Xtl => Fuel::Diesel,
            self::Home, self::Ac, self::Dc, self::DcRapid, self::DcUltra => Fuel::Electricity,
            default => Fuel::Petrol,
        };
    }

    /**
     * The regions (ISO 3166 codes) whose pumps sell this grade, or none for
     * a grade offered to everyone. Regional grades are only listed in their
     * family's group for owners in those regions (under "More grades" for
     * everyone else).
     *
     * @return list<string>
     */
    public function regions(): array
    {
        return match ($this) {
            self::E15 => ['US'],
            self::E20 => ['IN'],
            self::Aki87, self::Aki89, self::Aki91 => ['US', 'CA'],
            default => [],
        };
    }

    public function isRegional(): bool
    {
        return $this->regions() !== [];
    }

    /**
     * Whether it belongs in its family's group for an owner in $region
     * (null: the locale names no region).
     */
    public function isMainFor(?string $region): bool
    {
        return !$this->isRegional() || ($region !== null && in_array($region, $this->regions(), true));
    }

    public function labelKey(): string
    {
        return 'fuel.grade.' . $this->value;
    }

    public function shortLabelKey(): string
    {
        return 'fuel.grade_short.' . $this->value;
    }

    /**
     * Other words a CSV file may use for it, beyond its code, label and
     * short label (spec.md §7.13). Never a word that could mean several
     * grades ("Unleaded", "Super", "Diesel", "Premium").
     *
     * @return list<string>
     */
    public function importAliases(): array
    {
        return match ($this) {
            self::E10_95 => ['E10'],
            self::E5_95 => ['E5'],
            self::Xtl => ['HVO', 'HVO100', 'XTL'],
            self::Dc => ['DC charging'],
            self::DcRapid => ['Rapid charging'],
            self::DcUltra => ['Ultra rapid', 'HPC'],
            self::Ac => ['AC charging'],
            default => [],
        };
    }

    /**
     * The family a vehicle's default grade comes from: its own fuel type,
     * petrol for either kind of hybrid, none for LPG and other (they have no grades).
     */
    public static function defaultFamilyFor(FuelType $type): ?Fuel
    {
        return match ($type) {
            FuelType::Lpg, FuelType::Other => null,
            default => Fuel::defaultFor($type),
        };
    }

    /**
     * Every grade of a family, in picker order (none for LPG and other).
     *
     * @return list<self>
     */
    public static function forFamily(Fuel $family): array
    {
        return array_values(array_filter(self::cases(), static fn (self $grade): bool => $grade->family() === $family));
    }
}
