<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Service\Odometer\OdometerWarning;

/**
 * One line of the sale pack's mileage record (spec.md §7.19).
 */
final readonly class EvidenceReading
{
    public function __construct(
        public OdometerReading $reading,
        public EvidenceSource $source,
        /** The id of the entry that owns the reading (the reading's own for a photo). */
        public int $entryId,
        /** Kilometres since the listed reading before it; null for the first. */
        public ?string $deltaKm,
        /** Files on the owning entry. */
        public int $files,
        /** The Mileage tab's warning for it, judged against the whole series (§7.2). */
        public ?OdometerWarning $warning = null,
        /** A service record's garage. */
        public ?string $vendor = null,
        /** A document's type. */
        public ?ComplianceType $documentType = null,
        /** A tyre change's kind. */
        public ?TyreChangeKind $tyreKind = null,
    ) {
    }

    public function looksWrong(): bool
    {
        return $this->warning !== null;
    }
}
