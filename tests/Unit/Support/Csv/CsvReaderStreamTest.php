<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Csv;

use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Csv\CsvTooLarge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a CSV file from disk a chunk at a time (spec.md §7.13, K129):
 * the same rows as reading it whole, whatever the encoding and wherever a
 * character falls against the 1 MB chunks, and a file whose rows don't fit
 * the memory left is refused rather than ending the request.
 */
final class CsvReaderStreamTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodings(): iterable
    {
        // Rows of multi-byte characters (é, €, a four-byte emoji) longer than a chunk, so some fall across a boundary.
        $text = "Station,Notes\n";
        for ($i = 0; $i < 9000; $i++) {
            $text .= sprintf("Caf\u{e9} %d,\"%s \u{20ac}\u{1F697} line\nnext\"\n", $i, str_repeat("\u{e9}", 50 + $i % 7));
        }
        yield 'UTF-8' => [$text];
        yield 'UTF-8 with a byte-order mark' => ["\xEF\xBB\xBF" . $text];
        yield 'UTF-16LE' => ["\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')];
        yield 'UTF-16BE' => ["\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')];
        // One invalid byte late in the file makes the whole of it Windows-1252, as reading it whole did.
        yield 'Windows-1252' => [str_repeat("Note\n", 1) . str_repeat("Caf\xE9 \x80 5\n", 250000)];
        yield 'NULs' => ["Station\n" . str_repeat("Te\0sco\n", 200000)];
    }

    #[DataProvider('encodings')]
    public function testAFileReadsAsTheSameTextReadWhole(string $contents): void
    {
        self::assertGreaterThan(1024 * 1024, strlen($contents), 'longer than a chunk');
        $fromFile = CsvReader::fromFile($this->file($contents), 1_000_000);
        $whole = CsvReader::read($contents, 1_000_000);

        self::assertNotNull($fromFile);
        self::assertNotNull($whole);
        self::assertSame($whole->header, $fromFile->header);
        self::assertSame($whole->rows, $fromFile->rows);
        self::assertTrue(mb_check_encoding((string) json_encode($fromFile->rows, JSON_UNESCAPED_UNICODE), 'UTF-8'));
        self::assertSame(CsvReader::isBinary($contents), CsvReader::isBinaryFile($this->file($contents)));
    }

    public function testTheTextIsConvertedAsBefore(): void
    {
        $cp1252 = CsvReader::fromFile($this->file("Note\nCaf\xE9 \x80 5\n"), 10);
        $le = "\xFF\xFE" . mb_convert_encoding("Station\nT\u{e9}sco\n", 'UTF-16LE', 'UTF-8');
        $utf16 = CsvReader::fromFile($this->file($le), 10);
        $nul = CsvReader::fromFile($this->file("Station\nTe\0sco\n"), 10);

        self::assertSame("Caf\u{e9} \u{20ac} 5", $cp1252?->rows[0]['cells'][0]);
        self::assertSame("T\u{e9}sco", $utf16?->rows[0]['cells'][0]);
        self::assertSame('Tesco', $nul?->rows[0]['cells'][0]);
    }

    public function testCutOffUtf16IsBinaryAndNotRead(): void
    {
        $text = "\xFF\xFE" . mb_convert_encoding(str_repeat("Station\nT\u{e9}sco\n", 100000), 'UTF-16LE', 'UTF-8');
        foreach ([$text . 'x', $text . "\x3D\xD8"] as $damaged) {
            $path = $this->file($damaged);
            self::assertTrue(CsvReader::isBinaryFile($path));
            self::assertNull(CsvReader::fromFile($path, 1_000_000));
        }
        self::assertTrue(CsvReader::isBinaryFile($this->file("PK\x03\x04rest")));
        self::assertTrue(CsvReader::isBinaryFile('/no/such/file'));
        self::assertNull(CsvReader::fromFile('/no/such/file', 10));
    }

    public function testPeakMemoryIsAboutTheFileSizeNotThreeTimesIt(): void
    {
        // 20 MB in 2,000 rows of long notes: what is held is the rows, not the text as well (K129).
        $row = str_repeat('x', 10_000);
        $path = $this->file("Notes\n" . str_repeat($row . "\n", 2000));
        $size = (int) filesize($path);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $csv = CsvReader::fromFile($path, 5000);

        $peak = memory_get_peak_usage() - $before;
        self::assertNotNull($csv);
        self::assertCount(2000, $csv->rows);
        self::assertLessThan(1.5 * $size, $peak, sprintf('peak %.1f MB for a %.1f MB file', $peak / 1e6, $size / 1e6));
    }

    public function testRowsOverTheMemoryBudgetAreRefused(): void
    {
        $path = $this->file("Notes\n" . str_repeat(str_repeat('x', 1000) . "\n", 500));

        $this->expectException(CsvTooLarge::class);
        CsvReader::fromFile($path, 5000, 100_000);
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-csv-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
