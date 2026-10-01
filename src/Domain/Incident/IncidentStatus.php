<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * Whether anything is still to be done (spec.md §6 Incident).
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function labelKey(): string
    {
        return 'incident.status.' . $this->value;
    }
}
