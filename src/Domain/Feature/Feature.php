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
    case Trips = 'trips';
    case Incidents = 'incidents';
    case Finance = 'finance';
    // Phase 40.1 (spec.md §7.37): faults noticed and not yet fixed.
    case Issues = 'issues';
    // Phase 50.1 (spec.md §7.39): parts and modifications.
    case Parts = 'parts';
    // Phase 30.1: part of the fuel pages, so off whenever fuel is.
    case Stations = 'stations';
    // AI features (Phase 26.1, spec.md §7.25): on by default but inert, and
    // not listed, until a task is assigned.
    case AiAsk = 'ai_ask';
    case AiActions = 'ai_actions';
    // Reading files (spec.md §7.27): `ai_scan` until Phase 53.4 (#517), now
    // also read without AI, so listed whenever a model or OCR can read.
    case Scan = 'scan';

    /**
     * The environment variable holding the default, e.g. FEATURES_FUEL.
     */
    public function envName(): string
    {
        return 'FEATURES_' . strtoupper($this->value);
    }

    /**
     * The module's name before it was renamed (Phase 53.4, #517): a stored
     * choice or a FEATURES_ variable under it still applies when the new
     * name has none.
     */
    public function formerName(): ?string
    {
        return $this === self::Scan ? 'ai_scan' : null;
    }

    /**
     * On unless the environment says otherwise, except trips (Phase 22):
     * a specialist module most owners never need.
     */
    public function isOnByDefault(): bool
    {
        return $this !== self::Trips;
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
            self::Trips => 'route',
            self::Incidents => 'car_crash',
            self::Finance => 'account_balance',
            self::Issues => 'report',
            self::Parts => 'handyman',
            self::Stations => 'pin_drop',
            self::AiAsk => 'forum',
            self::AiActions => 'smart_toy',
            self::Scan => 'document_scanner',
        };
    }

    /**
     * The module this one is part of: with that one off, this one is off
     * too, whatever its own switch says (spec.md §7.10).
     */
    public function requires(): ?self
    {
        return match ($this) {
            self::Stations => self::Fuel,
            default => null,
        };
    }

    /**
     * An AI feature's module: listed on Settings → Modules only while AI
     * is set up (spec.md §7.10, §7.25).
     */
    public function isAi(): bool
    {
        return in_array($this, [self::AiAsk, self::AiActions], true);
    }

    /**
     * Not in the API's module list (`GET /me`): the AI modules and reading
     * files have no API (spec.md §7.20, §7.25).
     */
    public function hasApi(): bool
    {
        return !$this->isAi() && $this !== self::Scan;
    }
}
