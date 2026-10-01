<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Incident\IncidentData;

/**
 * A parsed incident form: the incident, and its odometer (canonical km),
 * which is written as a reading rather than stored on the incident.
 */
final readonly class IncidentInput
{
    public function __construct(
        public IncidentData $data,
        public ?string $odometerKm = null,
    ) {
    }
}
