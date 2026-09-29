<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Zip;

use DateTimeImmutable;
use InvalidArgumentException;
use Logbook\Support\Zip\StoredZipStream;
use Logbook\Support\Zip\ZipEntry;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The streamed ZIP (spec.md §7.19): read back by PHP's zip extension, with
 * UTF-8 names, exact sizes and small reads across part boundaries.
 */
final class StoredZipStreamTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logbook-zip-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAnArchiveTheZipExtensionReads(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Needs the zip extension to read the result back.');
        }
        $pdf = $this->dir . '/a.pdf';
        file_put_contents($pdf, "%PDF-1.7\n" . random_bytes(5000));
        file_put_contents($this->dir . '/empty.txt', '');
        $when = new DateTimeImmutable('2024-03-12 12:00:00');
        $stream = new StoredZipStream([
            ZipEntry::file('2024-03-12 Inspektion - Autohaus Müller.pdf', $pdf, $when),
            ZipEntry::file('empty.txt', $this->dir . '/empty.txt', $when),
            ZipEntry::text('contents.txt', "Line one\r\n", $when),
        ]);

        // Pulled in small, odd-sized reads, as an emitter might.
        $bytes = '';
        while (!$stream->eof()) {
            $bytes .= $stream->read(777);
        }
        self::assertSame($stream->getSize(), strlen($bytes), 'Content-Length is exact');
        self::assertSame($bytes, (string) $stream, 'and it rewinds');

        $out = $this->dir . '/out.zip';
        file_put_contents($out, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($out, ZipArchive::CHECKCONS));
        self::assertSame(3, $zip->numFiles);
        self::assertSame(file_get_contents($pdf), $zip->getFromName('2024-03-12 Inspektion - Autohaus Müller.pdf'));
        self::assertSame('', $zip->getFromName('empty.txt'));
        self::assertSame("Line one\r\n", $zip->getFromName('contents.txt'));
        $stat = $zip->statName('contents.txt');
        self::assertIsArray($stat);
        self::assertSame(ZipArchive::CM_STORE, $stat['comp_method']);
        self::assertSame('2024-03-12 12:00', date('Y-m-d H:i', $stat['mtime']));
        $zip->close();
    }

    public function testAnEmptyArchive(): void
    {
        $stream = new StoredZipStream([]);

        self::assertSame(22, $stream->getSize());
        self::assertSame("PK\x05\x06", substr($stream->getContents(), 0, 4));
    }

    public function testItIsReadOnly(): void
    {
        $stream = new StoredZipStream([]);

        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        $this->expectException(\RuntimeException::class);
        $stream->write('x');
    }

    public function testUnsafeNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ZipEntry::text('../evil.txt', 'x', new DateTimeImmutable());
    }
}
