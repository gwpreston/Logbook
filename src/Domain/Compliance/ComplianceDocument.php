<?php

declare(strict_types=1);

namespace Logbook\Domain\Compliance;

use DateTimeImmutable;

/**
 * An insurance policy, pollution certificate, registration or inspection
 * (spec.md §6 ComplianceDocument). Its expiry feeds reminders (Phase 4).
 */
final readonly class ComplianceDocument
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public ComplianceDocumentData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it (Phase 19); null = the vehicle's owner, or a former user. */
        public ?int $createdBy = null,
    ) {
    }
}
