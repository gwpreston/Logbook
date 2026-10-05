<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerSource;

/**
 * Where a reading in the sale pack's mileage record comes from (spec.md
 * §7.19): a reading a buyer can check, because a third party was there or a
 * photo shows it. Fill-up readings and manual readings without a file are
 * never in the record.
 */
enum EvidenceSource: string
{
    case Service = 'service';
    case Document = 'document';
    case Tyre = 'tyre';
    case Photo = 'photo';

    /**
     * The record's source for a reading's own, or null when it is not
     * evidence of itself (fill-ups; manual readings, until they have a file).
     */
    public static function of(OdometerSource $source): ?self
    {
        return match ($source) {
            OdometerSource::Maintenance => self::Service,
            OdometerSource::Document => self::Document,
            OdometerSource::Tyre => self::Tyre,
            OdometerSource::Manual => self::Photo,
            // An incident is not the buyer's evidence (spec.md §7.29: only its repairs are), and
            // the mileage when bought is the owner's own statement.
            OdometerSource::Fuel, OdometerSource::Incident, OdometerSource::Purchase => null,
        };
    }

    /**
     * The module that must be on for its readings to be listed (null: core).
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Service => Feature::Maintenance,
            self::Document => Feature::Compliance,
            self::Tyre => Feature::Tyres,
            self::Photo => null,
        };
    }

    /**
     * The entry's edit page (route name) and its placeholder, for the
     * seller notice.
     *
     * @return array{0: string, 1: string}
     */
    public function editRoute(): array
    {
        return match ($this) {
            self::Service => ['maintenance.edit', 'entry'],
            self::Document => ['compliance.edit', 'document'],
            self::Tyre => ['tyres.changes.edit', 'change'],
            self::Photo => ['odometer.edit', 'reading'],
        };
    }
}
