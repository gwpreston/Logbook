<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use PHPUnit\Framework\TestCase;

/**
 * Purchase and sale paperwork needs its date (spec.md §7.1, §7.12): no file
 * is ever left with no milestone to show on.
 */
final class PaperworkNeedsDateTest extends TestCase
{
    public function testFilesWithTheirDatesAreFine(): void
    {
        self::assertNull(PaperworkNeedsDate::check(self::data('2021-05-01', '2026-01-10'), 2, 1, 1, 3));
        self::assertNull(PaperworkNeedsDate::check(self::data(null, null), 0, 0, 0, 0), 'no files, no dates');
    }

    public function testNewFilesWithoutTheirDateAreRefused(): void
    {
        $sale = PaperworkNeedsDate::check(self::data('2021-05-01', null), 0, 1, 0, 2);
        self::assertNotNull($sale);
        self::assertSame(AttachmentOwner::Sale, $sale->side);
        self::assertFalse($sale->clearing);
        self::assertSame('sale_attachments', $sale->field());
        self::assertSame('vehicle.error.sale_files_need_date', $sale->messageKey());

        $purchase = PaperworkNeedsDate::check(self::data(null, '2026-01-10'), 0, 1, 0, 0);
        self::assertNotNull($purchase);
        self::assertSame('vehicle.error.purchase_files_need_date', $purchase->messageKey());
        self::assertSame('purchase_attachments', $purchase->field());
    }

    public function testClearingADateWithFilesIsRefused(): void
    {
        $refused = PaperworkNeedsDate::check(self::data('2021-05-01', null), 0, 0, 2, 0);
        self::assertNotNull($refused);
        self::assertSame(AttachmentOwner::Sale, $refused->side);
        self::assertTrue($refused->clearing);
        self::assertSame('sale_date', $refused->field());
        self::assertSame('vehicle.error.sale_date_has_files', $refused->messageKey());

        $both = PaperworkNeedsDate::check(self::data(null, null), 1, 1, 0, 0);
        self::assertNotNull($both);
        self::assertTrue($both->clearing, 'clearing with files kept is reported before the new ones');
        self::assertSame('vehicle.error.purchase_date_has_files', $both->messageKey());
    }

    public function testClearingADateWithoutFilesIsAllowed(): void
    {
        self::assertNull(PaperworkNeedsDate::check(self::data(null, null), 0, 0, 0, 0));
        self::assertNull(PaperworkNeedsDate::check(self::data('2021-05-01', null), 3, 0, 0, 0), 'the other side keeps its files');
    }

    private static function data(?string $purchased, ?string $sold): VehicleData
    {
        $utc = new DateTimeZone('UTC');
        $date = static fn (?string $d): ?DateTimeImmutable => $d === null ? null : new DateTimeImmutable($d, $utc);

        return new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            purchaseDate: $date($purchased),
            saleDate: $date($sold),
        );
    }
}
