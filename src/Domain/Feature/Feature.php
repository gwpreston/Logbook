<?php

declare(strict_types=1);

namespace Logbook\Domain\Feature;

/**
 * Modules that can be switched off instance-wide (spec.md §7.10). The
 * garage, mileage log and expenses are core and always on.
 */
enum Feature: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Reminders = 'reminders';
    case Reports = 'reports';
    case Tyres = 'tyres';

    /**
     * The environment variable holding the default, e.g. FEATURES_FUEL.
     */
    public function envName(): string
    {
        return 'FEATURES_' . strtoupper($this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
            self::Compliance => 'verified_user',
            self::Reminders => 'notifications',
            self::Reports => 'bar_chart',
            self::Tyres => 'tire_repair',
        };
    }
}
