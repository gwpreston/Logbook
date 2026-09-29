<?php

declare(strict_types=1);

namespace Logbook\Action\Log;

use Logbook\Domain\Feature\Feature;

/**
 * The choices of the "Log something" chooser (spec.md §7.3), in the design's
 * order: the form each opens, the module it belongs to and its icon.
 */
enum LogKind: string
{
    case Fuel = 'fuel';
    case Odometer = 'odometer';
    case Maintenance = 'maintenance';
    case Expense = 'expense';
    case Document = 'document';
    case Schedule = 'schedule';
    case Tyre = 'tyre';

    /**
     * The vehicle's add form (route name; takes the vehicle `id`).
     */
    public function createRoute(): string
    {
        return match ($this) {
            self::Fuel => 'fuel.create',
            self::Odometer => 'odometer.create',
            self::Maintenance => 'maintenance.create',
            self::Expense => 'expenses.create',
            self::Document => 'compliance.create',
            self::Schedule => 'maintenance.schedules.create',
            self::Tyre => 'tyres.change',
        };
    }

    /**
     * Route placeholders beyond the vehicle `id`.
     *
     * @return array<string, string>
     */
    public function createParams(): array
    {
        return $this === self::Tyre ? ['kind' => 'fit'] : [];
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
            self::Tyre => Feature::Tyres,
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
        };
    }

    /**
     * Colour token of the icon (the design tints each choice).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fuel => 'accent',
            self::Odometer => 'text',
            self::Maintenance, self::Schedule, self::Tyre => 'c-maint',
            self::Expense => 'c-other',
            self::Document => 'c-ins',
        };
    }
}
