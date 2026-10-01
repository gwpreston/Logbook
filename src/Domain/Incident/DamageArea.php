<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * Where the vehicle was damaged (spec.md §6 Incident).
 */
enum DamageArea: string
{
    case Front = 'front';
    case Rear = 'rear';
    case Left = 'left';
    case Right = 'right';
    case Roof = 'roof';
    case Underside = 'underside';
    case Glass = 'glass';
    case Wheels = 'wheels';
    case Interior = 'interior';

    public function labelKey(): string
    {
        return 'incident.area.' . $this->value;
    }
}
