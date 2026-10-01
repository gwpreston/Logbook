<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * Whether the claim affects the no-claims discount (spec.md §6 Incident).
 */
enum NcdEffect: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'incident.ncd.' . $this->value;
    }
}
