<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * What kind of modification it is (spec.md §7.39, #424), in display order:
 * the owner's eight first. Stored as text, so a new one needs no migration.
 */
enum ModificationCategory: string
{
    case Exhaust = 'exhaust';
    case Suspension = 'suspension';
    case Wheels = 'wheels';
    /** The modification with no part, the one most often reverted. */
    case Tune = 'tune';
    case Intake = 'intake';
    case Audio = 'audio';
    case Lighting = 'lighting';
    case Body = 'body';
    case Engine = 'engine';
    case Brakes = 'brakes';
    case Interior = 'interior';
    case Electrical = 'electrical';
    case Security = 'security';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'modification.category.' . $this->value;
    }
}
