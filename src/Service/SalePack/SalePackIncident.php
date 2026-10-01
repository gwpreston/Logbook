<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use DateTimeImmutable;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\Severity;

/**
 * An incident as the sale pack shows it (spec.md §7.29 *Sale pack*): what
 * was damaged and who repaired it. Never the fault, claim, payout, driver,
 * location or other party: this class has no field for them.
 */
final readonly class SalePackIncident
{
    /**
     * @param list<DamageArea> $damageAreas
     * @param list<array{date: DateTimeImmutable, title: string, vendor: ?string}> $repairs oldest first
     */
    public function __construct(
        public DateTimeImmutable $date,
        public IncidentType $type,
        public array $damageAreas,
        public ?Severity $severity,
        public array $repairs,
    ) {
    }
}
