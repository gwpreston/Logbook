<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Scan;

use Logbook\Domain\Feature\Feature;

/**
 * The form a scan opens (spec.md §7.27 *Mapping to Logbook*).
 */
enum ScanTarget: string
{
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Vehicle = 'vehicle';
    /** An incident's form (Phase 27.2): the matching incident's edit form, or *Log incident*. */
    case Incident = 'incident';

    /**
     * The module the form belongs to; null for the vehicle (core).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Fuel => Feature::Fuel,
            self::Maintenance => Feature::Maintenance,
            self::Document => Feature::Compliance,
            self::Incident => Feature::Incidents,
            self::Vehicle => null,
        };
    }

    /**
     * The create form's route; the vehicle page has its own.
     */
    public function route(): string
    {
        return match ($this) {
            self::Fuel => 'fuel.create',
            self::Maintenance => 'maintenance.create',
            self::Document => 'compliance.create',
            self::Incident => 'incidents.create',
            self::Vehicle => 'scan.vehicle',
        };
    }

    /**
     * The kind read when nothing better is known, for a scan started from this form.
     */
    public function defaultKind(): ScanKind
    {
        return match ($this) {
            self::Fuel => ScanKind::FuelReceipt,
            self::Maintenance => ScanKind::ServiceInvoice,
            self::Document => ScanKind::Other,
            self::Incident => ScanKind::ClaimLetter,
            self::Vehicle => ScanKind::Registration,
        };
    }
}
