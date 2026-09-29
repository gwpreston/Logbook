<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Service\Attachment\AttachmentCounts;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Support\Number\Decimal;

/**
 * The sale pack's mileage record (spec.md §7.19): the readings a buyer can
 * check, oldest first. Every service, document and tyre reading, and manual
 * readings with a file (a dashboard photo); never fill-up readings. Each
 * carries the distance since the listed reading before it, the files on its
 * entry and, for the seller only, the Mileage tab's plausibility warning:
 * judged against the whole series, as that tab judges it, so a reading
 * flagged there is flagged here.
 *
 * A switched-off module's readings are left out. Pure: no I/O.
 */
final class MileageEvidence
{
    /**
     * @param array<string, bool> $enabled FeatureToggles::all()
     * @param array<int, MaintenanceEntry> $services by id
     * @param array<int, ComplianceDocument> $documents by id
     * @param array<int, TyreChange> $changes by id
     * @return list<EvidenceReading> oldest first
     */
    public static function select(
        OdometerHistory $history,
        AttachmentCounts $counts,
        array $enabled,
        array $services = [],
        array $documents = [],
        array $changes = [],
    ): array {
        $listed = [];
        $previous = null;
        foreach ($history->readings as $reading) {
            $source = EvidenceSource::of($reading->source);
            if ($source === null || ($source->feature() !== null && !($enabled[$source->feature()->value] ?? false))) {
                continue;
            }
            // A tyre change takes no files (its receipt is on the linked record); the rest count their entry's.
            [$owner, $entryId] = $reading->filesOwner();
            $files = $counts->of($owner, $entryId);
            if ($reading->source === OdometerSource::Manual && $files === 0) {
                continue;
            }
            if ($reading->source === OdometerSource::Tyre) {
                $entryId = $reading->tyreChangeId ?? $reading->id;
            }

            $listed[] = new EvidenceReading(
                reading: $reading,
                source: $source,
                entryId: $entryId,
                deltaKm: $previous === null ? null : Decimal::subtract($reading->readingKm, $previous->readingKm),
                files: $files,
                warning: $history->warningFor($reading->id),
                vendor: $owner === AttachmentOwner::Maintenance ? ($services[$entryId] ?? null)?->data->vendor : null,
                documentType: $owner === AttachmentOwner::Compliance ? ($documents[$entryId] ?? null)?->data->type : null,
                tyreKind: $reading->source === OdometerSource::Tyre ? ($changes[$entryId] ?? null)?->kind : null,
            );
            $previous = $reading;
        }

        return $listed;
    }

    /**
     * Whether any listed reading looks wrong (the seller notice).
     *
     * @param list<EvidenceReading> $readings
     */
    public static function hasWarnings(array $readings): bool
    {
        foreach ($readings as $reading) {
            if ($reading->looksWrong()) {
                return true;
            }
        }

        return false;
    }
}
