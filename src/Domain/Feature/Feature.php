<?php

declare(strict_types=1);

namespace Logbook\Domain\Feature;

/**
 * Modules that can be switched off instance-wide (spec.md §7.10).
 */
enum Feature: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Compliance = 'compliance';
    case Reminders = 'reminders';
    case Reports = 'reports';

    /**
     * The environment variable holding the default, e.g. FEATURES_FUEL.
     */
    public function envName(): string
    {
        return 'FEATURES_' . strtoupper($this->value);
    }
}
