<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\SalePack;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Attachment\AttachmentCounts;
use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Service\Odometer\OdometerWarning;
use Logbook\Service\SalePack\EvidenceSource;
use Logbook\Service\SalePack\MileageEvidence;
use PHPUnit\Framework\TestCase;

/**
 * The sale pack's mileage record (spec.md §7.19): only readings a buyer can
 * check, with the Mileage tab's warnings for the seller.
 */
final class MileageEvidenceTest extends TestCase
{
    private const array ALL_ON = ['maintenance' => true, 'compliance' => true, 'tyres' => true];

    public function testOnlyReadingsABuyerCanCheck(): void
    {
        $history = new OdometerHistory([
            self::reading(1, '10000', '2024-01-10', OdometerSource::Manual),
            self::reading(2, '10500', '2024-02-10', OdometerSource::Fuel, fuel: 7),
            self::reading(3, '11000', '2024-03-12', OdometerSource::Maintenance, maintenance: 21),
            self::reading(4, '12000', '2024-05-01', OdometerSource::Manual),
            self::reading(5, '13000', '2024-06-01', OdometerSource::Document, document: 31),
            self::reading(6, '14000', '2024-08-01', OdometerSource::Tyre, tyre: 41),
        ]);
        $counts = new AttachmentCounts([
            AttachmentCounts::key(AttachmentOwner::Odometer, 4) => 1,
            AttachmentCounts::key(AttachmentOwner::Maintenance, 21) => 2,
            AttachmentCounts::key(AttachmentOwner::Fuel, 7) => 1,
        ]);
        $service = new MaintenanceEntry(
            21,
            1,
            new MaintenanceEntryData(
                new DateTimeImmutable('2024-03-12'),
                MaintenanceCategory::Service,
                'Annual service',
                vendor: 'Kwik Fit',
            ),
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );

        $listed = MileageEvidence::select($history, $counts, self::ALL_ON, [21 => $service]);

        $ids = array_map(static fn ($e): int => $e->reading->id, $listed);
        self::assertSame([3, 4, 5, 6], $ids, 'no fill-up, no manual reading without a file');
        self::assertSame(
            [EvidenceSource::Service, EvidenceSource::Photo, EvidenceSource::Document, EvidenceSource::Tyre],
            array_map(static fn ($e): EvidenceSource => $e->source, $listed),
        );
        self::assertNull($listed[0]->deltaKm, 'the first listed');
        self::assertSame('1000', $listed[1]->deltaKm, 'since the listed one before, not the fill-up');
        self::assertSame([2, 1, 0, 0], array_map(static fn ($e): int => $e->files, $listed));
        self::assertSame('Kwik Fit', $listed[0]->vendor);
        self::assertSame([21, 4, 31, 41], array_map(static fn ($e): int => $e->entryId, $listed), 'for the seller\'s link');
        self::assertFalse(MileageEvidence::hasWarnings($listed));
    }

    public function testABackwardsReadingIsFlaggedAsTheMileageTabFlagsIt(): void
    {
        $history = new OdometerHistory([
            self::reading(1, '10000', '2024-01-10', OdometerSource::Maintenance, maintenance: 1),
            self::reading(2, '15000', '2024-02-10', OdometerSource::Fuel, fuel: 2),
            // Lower than the fill-up before it, though above the last listed reading.
            self::reading(3, '12000', '2024-03-12', OdometerSource::Maintenance, maintenance: 3),
        ]);

        $listed = MileageEvidence::select($history, new AttachmentCounts(), self::ALL_ON);

        self::assertCount(2, $listed);
        self::assertTrue(MileageEvidence::hasWarnings($listed));
        self::assertNotNull($listed[1]->warning);
        self::assertSame(OdometerWarning::BACKWARDS, $listed[1]->warning->type);
        self::assertSame('2000', $listed[1]->deltaKm);
    }

    public function testASwitchedOffModulesReadingsLeave(): void
    {
        $history = new OdometerHistory([
            self::reading(1, '10000', '2024-01-10', OdometerSource::Maintenance, maintenance: 1),
            self::reading(2, '11000', '2024-02-10', OdometerSource::Document, document: 2),
            self::reading(3, '12000', '2024-03-12', OdometerSource::Tyre, tyre: 3),
        ]);

        $enabled = ['maintenance' => false, 'compliance' => true, 'tyres' => false];
        $listed = MileageEvidence::select($history, new AttachmentCounts(), $enabled);

        self::assertSame([2], array_map(static fn ($e): int => $e->reading->id, $listed));
    }

    private static function reading(
        int $id,
        string $km,
        string $day,
        OdometerSource $source,
        ?int $fuel = null,
        ?int $maintenance = null,
        ?int $document = null,
        ?int $tyre = null,
    ): OdometerReading {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');

        return new OdometerReading(
            $id,
            1,
            $km,
            new DateTimeImmutable($day . 'T12:00:00Z'),
            $source,
            null,
            $fuel,
            $now,
            $now,
            $maintenance,
            $document,
            $tyre,
        );
    }
}
