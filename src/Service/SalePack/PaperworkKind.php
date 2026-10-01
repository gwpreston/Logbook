<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Feature\Feature;

/**
 * The kinds of paperwork the sale pack's ZIP offers (spec.md §7.19), with
 * their defaults. Anything else is never offered, whatever the request asks
 * for: see NEVER_OFFERED.
 */
enum PaperworkKind: string
{
    /** Service and repair records' invoices. */
    case Service = 'service';
    /** Inspection (MOT) and pollution certificates. */
    case Inspection = 'inspection';
    /** Photos on manual readings (a dashboard photo). */
    case Photo = 'photo';
    /** The purchase paperwork, on the *Bought* milestone. */
    case Purchase = 'purchase';
    case Insurance = 'insurance';
    /** Incident photos (Phase 27.1): only with *Include incidents*, stripped as they are written. */
    case IncidentPhoto = 'incident_photos';

    /**
     * Never offered, and never read from a request: registration documents
     * (the V5C carries a reference that can be used for fraud), `other`
     * documents, sale paperwork, valuations, fill-ups and expenses. Listed
     * for the docs and the tests; the selector simply has no case for them.
     */
    public const array NEVER_OFFERED = ['registration', 'other', 'sale', 'valuation', 'fuel', 'expense'];

    /**
     * Ticked until the seller changes it.
     */
    public function isDefault(): bool
    {
        return match ($this) {
            self::Service, self::Inspection, self::Photo => true,
            self::Purchase, self::Insurance, self::IncidentPhoto => false,
        };
    }

    /**
     * The module that must be on for the kind to be offered (null: core).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Service => Feature::Maintenance,
            self::Inspection, self::Insurance => Feature::Compliance,
            self::IncidentPhoto => Feature::Incidents,
            self::Photo, self::Purchase => null,
        };
    }

    /**
     * The kinds whose module is on, in offer order.
     *
     * @param array<string, bool> $enabled FeatureToggles::all()
     * @return list<self>
     */
    public static function offered(array $enabled): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $kind->feature() === null || ($enabled[$kind->feature()->value] ?? false),
        ));
    }
}
