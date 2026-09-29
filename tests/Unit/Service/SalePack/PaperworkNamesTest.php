<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\SalePack;

use DateTimeImmutable;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\SalePack\PaperworkKind;
use Logbook\Service\SalePack\PaperworkSelector;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * Names in the sale pack's ZIP (spec.md §7.19): readable, safe on every
 * file system, unique.
 */
final class PaperworkNamesTest extends TestCase
{
    public function testDateKindAndWhat(): void
    {
        $day = LocalTime::parseDate('2024-03-12');
        self::assertNotNull($day);

        self::assertSame('2024-03-12 Service - Kwik Fit', PaperworkSelector::name($day, 'Service', 'Kwik Fit'));
        self::assertSame('2024-03-12 Dashboard photo', PaperworkSelector::name($day, 'Dashboard photo', null));
        self::assertSame('2024-03-12 MOT', PaperworkSelector::name($day, 'MOT', 'mot'), 'not "MOT - mot"');
        self::assertSame('2024-03-12 Repair - A B C D', PaperworkSelector::name($day, 'Repair', "A/B\\C:\x07D?"));
        self::assertSame('2024-03-12 Service - hidden', PaperworkSelector::name($day, 'Service', '..hidden...'));
        self::assertSame(100, mb_strlen(PaperworkSelector::name($day, 'Service', str_repeat('ä', 200))));
        self::assertSame('2024-03-12', PaperworkSelector::name($day, '', ' ... '));
    }

    public function testDuplicatesAreNumbered(): void
    {
        $taken = [];
        self::assertSame('2024-03-12 Service.pdf', PaperworkSelector::unique('2024-03-12 Service', 'pdf', $taken));
        self::assertSame('2024-03-12 Service (2).pdf', PaperworkSelector::unique('2024-03-12 Service', 'pdf', $taken));
        $third = PaperworkSelector::unique('2024-03-12 SERVICE', 'pdf', $taken);
        self::assertSame('2024-03-12 SERVICE (3).pdf', $third, 'without case');
        self::assertSame('2024-03-12 Service.jpg', PaperworkSelector::unique('2024-03-12 Service', 'jpg', $taken));
    }

    public function testTheExtensionIsTheFilesOwnElseItsTypes(): void
    {
        self::assertSame('pdf', PaperworkSelector::extension(self::attachment('Invoice.PDF', 'application/pdf')));
        self::assertSame('jpg', PaperworkSelector::extension(self::attachment('photo', 'image/jpeg')));
        self::assertSame('png', PaperworkSelector::extension(self::attachment('scan.<script>', 'image/png')));
        self::assertSame('bin', PaperworkSelector::extension(self::attachment('x', 'application/x-unknown')));
    }

    public function testRegistrationAndSalePaperworkAreNeverKinds(): void
    {
        $kinds = array_map(static fn (PaperworkKind $kind): string => $kind->value, PaperworkKind::cases());
        self::assertSame([], array_values(array_intersect($kinds, PaperworkKind::NEVER_OFFERED)));
        self::assertSame(
            [PaperworkKind::Photo, PaperworkKind::Purchase],
            PaperworkKind::offered(['maintenance' => false, 'compliance' => false]),
        );
    }

    private static function attachment(string $name, string $mime): Attachment
    {
        $path = 'attachments/x.pdf';

        return new Attachment(1, 1, AttachmentOwner::Maintenance, 1, $name, $mime, 10, $path, new DateTimeImmutable());
    }
}
