<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Scan;

/**
 * What a scanned file is (spec.md §7.27 *Classify, then extract*).
 */
enum ScanKind: string
{
    case ServiceInvoice = 'service_invoice';
    case FuelReceipt = 'fuel_receipt';
    case Inspection = 'inspection';
    case Insurance = 'insurance';
    case Registration = 'registration';
    case Other = 'other';
    /** An insurer's or broker's letter or email about a claim (Phase 27.2). */
    case ClaimLetter = 'claim_letter';
    /** A repairer's estimate (Phase 27.2): never a cost. */
    case RepairEstimate = 'repair_estimate';

    public function labelKey(): string
    {
        return 'scan.kind.' . $this->value;
    }

    /**
     * The form it fills.
     */
    public function target(): ScanTarget
    {
        return match ($this) {
            self::ServiceInvoice => ScanTarget::Maintenance,
            self::FuelReceipt => ScanTarget::Fuel,
            self::Inspection, self::Insurance, self::Other => ScanTarget::Document,
            self::Registration => ScanTarget::Vehicle,
            self::ClaimLetter, self::RepairEstimate => ScanTarget::Incident,
        };
    }
}
