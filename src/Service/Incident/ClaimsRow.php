<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * One incident in the claims history, as the user may see it. Never the
 * other party (spec.md §7.29).
 */
final readonly class ClaimsRow
{
    public function __construct(
        public IncidentView $incident,
        public Vehicle $vehicle,
        /** The driver's name (a user's, or as typed); null when hidden or none. */
        public ?string $driver,
        public string $currency,
    ) {
    }
}
