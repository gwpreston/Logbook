<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * How bad the damage was (spec.md §6 Incident).
 */
enum Severity: string
{
    case Cosmetic = 'cosmetic';
    case Minor = 'minor';
    case Major = 'major';

    public function labelKey(): string
    {
        return 'incident.severity.' . $this->value;
    }
}
