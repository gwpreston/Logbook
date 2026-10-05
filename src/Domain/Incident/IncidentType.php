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
}
