<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * What happened (spec.md §6 Incident).
 */
enum IncidentType: string
{
    case Collision = 'collision';
    case ParkedDamage = 'parked_damage';
    case Theft = 'theft';
    case BreakIn = 'break_in';
    case Vandalism = 'vandalism';
    case Weather = 'weather';
    case Glass = 'glass';
    case Pothole = 'pothole';
    case Animal = 'animal';
    case Fire = 'fire';
    /** A breakdown or recovery with no damage (Phase 33.3). */
    case Breakdown = 'breakdown';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'incident.type.' . $this->value;
    }

    /**
     * Its Material Symbols icon (spec.md §7.29 *Layout*): the incident cards,
     * the incident page and the claims history.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Collision => 'car_crash',
            self::ParkedDamage => 'local_parking',
            self::Theft => 'lock_open',
            self::BreakIn => 'door_open',
            self::Weather => 'thunderstorm',
            self::Glass => 'window',
            self::Pothole => 'warning',
            self::Animal => 'pets',
            self::Fire => 'local_fire_department',
            self::Breakdown => 'minor_crash',
            self::Vandalism, self::Other => 'report',
        };
    }
}
