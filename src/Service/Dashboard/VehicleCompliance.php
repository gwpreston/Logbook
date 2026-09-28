<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Compliance\DocumentStatus;

/**
 * Where one vehicle's current documents stand, for the compliance widget.
 */
final readonly class VehicleCompliance
{
    /**
     * @param list<DocumentState> $current current (not replaced) documents, most urgent first
     */
    public function __construct(
        public Vehicle $vehicle,
        public array $current,
    ) {
    }

    /**
     * Expired or expiring documents.
     *
     * @return list<DocumentState>
     */
    public function attention(): array
    {
        return array_values(array_filter(
            $this->current,
            static fn (DocumentState $s): bool => in_array($s->status, [DocumentStatus::Expired, DocumentStatus::Expiring], true),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->current === [];
    }
}
