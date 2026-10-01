<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * Whose fault the insurer holds it to be (spec.md §6 Incident).
 */
enum Fault: string
{
    case AtFault = 'at_fault';
    case NotAtFault = 'not_at_fault';
    case Split = 'split';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'incident.fault.' . $this->value;
    }
}
