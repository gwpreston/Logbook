<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * The one place that decides whether a vehicle's *First MOT due* date
 * still counts (spec.md §6, §7.1): only while the vehicle has no
 * `inspection` document at all, current, replaced or expired. Every page
 * that shows it (reminders, *Coming up*, the overview, the sale pack, the
 * form, the prompt) asks here.
 */
final readonly class FirstInspection
{
    public function __construct(private ComplianceService $compliance)
    {
    }

    /**
     * @param list<ComplianceDocument> $documents one vehicle's documents
     */
    public static function hasCertificate(array $documents): bool
    {
        foreach ($documents as $document) {
            if ($document->data->type === ComplianceType::Inspection) {
                return true;
            }
        }

        return false;
    }

    /**
     * The date while it counts: set, and no inspection document.
     *
     * @param list<ComplianceDocument> $documents the vehicle's documents
     */
    public static function pending(Vehicle $vehicle, array $documents): ?DateTimeImmutable
    {
        $on = $vehicle->data->firstInspectionDueOn;

        return $on === null || self::hasCertificate($documents) ? null : $on;
    }

    /**
     * The inspection document that now sets the next MOT: the current one
     * (the one running latest), or null before the first.
     *
     * @param list<ComplianceDocument> $documents the vehicle's documents
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function certificate(array $documents, DateTimeImmutable $today): ?ComplianceDocument
    {
        $inspections = array_values(array_filter(
            $documents,
            static fn (ComplianceDocument $d): bool => $d->data->type === ComplianceType::Inspection,
        ));
        if ($inspections === []) {
            return null;
        }
        foreach (DocumentState::evaluateAll($inspections, $today) as $state) {
            if ($state->status->isCurrent()) {
                return $state->document;
            }
        }

        return $inspections[0];
    }

    public function vehicleHasCertificate(Vehicle $vehicle): bool
    {
        return self::hasCertificate($this->compliance->list($vehicle));
    }

    /**
     * The vehicle's first MOT as the overview and the sale pack show it, or
     * null when it has none pending.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     * @param int $leadDays the owner's document lead time
     */
    public function due(Vehicle $vehicle, DateTimeImmutable $today, int $leadDays): ?FirstInspectionDue
    {
        $on = self::pending($vehicle, $this->compliance->list($vehicle));

        return $on === null ? null : FirstInspectionDue::on($on, $today, $leadDays);
    }

    /**
     * The current certificate, for the form's read-only *Done* text.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function currentCertificate(Vehicle $vehicle, DateTimeImmutable $today): ?ComplianceDocument
    {
        return self::certificate($this->compliance->list($vehicle), $today);
    }
}
