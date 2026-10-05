<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Expense\CostGroup;

/**
 * The parts of a vehicle's true cost (spec.md §7.35): the ledger's four
 * groups and depreciation, in display order. Labels and icons are fixed.
 */
enum TruePart: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Documents = 'compliance';
    case Other = 'other';
    case Depreciation = 'depreciation';

    public static function ofGroup(CostGroup $group): self
    {
        return match ($group) {
            CostGroup::Fuel => self::Fuel,
            CostGroup::Maintenance => self::Maintenance,
            CostGroup::Compliance => self::Documents,
            CostGroup::Other => self::Other,
        };
    }

    /**
     * The running parts: the ledger's groups.
     *
     * @return list<self>
     */
    public static function running(): array
    {
        return [self::Fuel, self::Maintenance, self::Documents, self::Other];
    }

    /**
     * Fixed with time, not with distance: *What changed* gives these a
     * distance line (§7.35; depreciation always time-based, #151).
     */
    public function isFixed(): bool
    {
        return $this === self::Documents || $this === self::Depreciation;
    }

    public function labelKey(): string
    {
        return 'true_cost.part.' . $this->value;
    }

    /**
     * Colour token in app.css, as the cost groups use.
     */
    public function color(): string
    {
        return match ($this) {
            self::Fuel => 'c-fuel',
            self::Maintenance => 'c-maint',
            self::Documents => 'c-ins',
            self::Other => 'c-other',
            self::Depreciation => 'c-tax',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
            self::Documents => 'verified_user',
            self::Other => 'payments',
            self::Depreciation => 'sell',
        };
    }
}
