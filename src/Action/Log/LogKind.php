<?php

declare(strict_types=1);

namespace Logbook\Action\Log;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;

/**
 * The choices of the "Log something" chooser (spec.md §7.3), in the design's
 * order: the form each opens, the module it belongs to and its icon.
 */
enum LogKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    /** A business trip (Phase 22). */
    case Trip = 'trip';
    case Maintenance = 'maintenance';
    case Expense = 'expense';
    case Document = 'document';
    case Schedule = 'schedule';
    case Tyre = 'tyre';
    /** *Check tread*, beside *Tyre change* (Phase 11.2). */
    case TyreCheck = 'tyre_check';

    /**
     * The vehicle's add form (route name; takes the vehicle `id`).
     */
    /**
     * What the create form's route needs on the vehicle (config/routes.php).
     */
    public function ability(): VehicleAbility
    {
        return $this === self::Schedule ? VehicleAbility::Manage : VehicleAbility::Log;
    }

    public function createRoute(): string
    {
        return match ($this) {
            self::Fuel => 'fuel.create',
            self::Odometer => 'odometer.create',
            self::Maintenance => 'maintenance.create',
            self::Expense => 'expenses.create',
            self::Document => 'compliance.create',
            self::Schedule => 'maintenance.schedules.create',
            self::Tyre, self::TyreCheck => 'tyres.change',
            self::Trip => 'trips.create',
        };
    }

    /**
     * Route placeholders beyond the vehicle `id`.
     *
     * @return array<string, string>
     */
    public function createParams(): array
    {
        return match ($this) {
            self::Tyre => ['kind' => 'fit'],
            self::TyreCheck => ['kind' => 'check'],
            default => [],
        };
    }

    /**
     * Switched off with this module; null = core (always available).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance, self::Schedule => Feature::Maintenance,
            self::Document => Feature::Compliance,
            self::Tyre, self::TyreCheck => Feature::Tyres,
            self::Trip => Feature::Trips,
            self::Odometer, self::Expense => null,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fuel => 'local_gas_station',
            self::Odometer => 'speed',
            self::Maintenance => 'build',
            self::Expense => 'payments',
            self::Document => 'description',
            self::Schedule => 'event_repeat',
            self::Tyre => 'tire_repair',
            self::TyreCheck => 'fact_check',
            self::Trip => 'route',
        };
    }

    /**
     * Colour token of the icon (the design tints each choice).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel => 'accent',
            self::Odometer, self::Trip => 'text',
            self::Maintenance, self::Schedule, self::Tyre, self::TyreCheck => 'c-maint',
            self::Expense => 'c-other',
            self::Document => 'c-ins',
        };
    }
}
