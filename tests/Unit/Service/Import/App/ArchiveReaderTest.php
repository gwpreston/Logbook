<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import\App;

use Logbook\Kernel;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ArchiveRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * ZIP safety (spec.md §7.13): every limit is checked before anything is
 * read, nothing is written outside the private temporary folder, and the
 * folder is gone afterwards.
 */
final class ArchiveReaderTest extends TestCase
{
    private string $root;
    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/logbook-archive-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            @unlink($file);
        }
        ArchiveReader::removeDirectory($this->root);
    }

    public function testTheOwnersBackupOpensWithItsCsvAndPhotos(): void
    {
        $archive = (new ArchiveReader($this->root))->open(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/backup.fuelio.zip');

        self::assertSame(['vehicle-1-local.csv'], array_column($archive->csv, 'name'));
        self::assertCount(45, $archive->photoNames());
        self::assertTrue($archive->hasPhoto('photo-001.jpg'));
        self::assertSame([], $archive->ignored);

        $path = $archive->extractPhoto('photo-001.jpg');
        self::assertNotNull($path);
        self::assertStringStartsWith($archive->directory() . '/', $path, 'under a name Logbook chose, in its own folder');
        self::assertSame(IMAGETYPE_JPEG, getimagesize($path)[2] ?? null);
        self::assertNull($archive->extractPhoto('../photo-001.jpg'));
        self::assertNull($archive->extractPhoto('missing.jpg'));

        $directory = $archive->directory();
        $archive->close();
        self::assertDirectoryDoesNotExist($directory, 'the temporary folder is removed');
        self::assertSame([], glob($this->root . '/*') ?: []);
    }

    /**
     * @param callable(ZipArchive): void $fill
     */
    #[DataProvider('refused')]
    public function testUnsafeArchivesAreRefusedBeforeReading(callable $fill, string $key): void
    {
        $path = $this->zip($fill);

        try {
            (new ArchiveReader($this->root))->open($path);
            self::fail('expected the archive to be refused');
        } catch (ArchiveRefused $e) {
            self::assertSame($key, $e->key);
        }
        self::assertSame([], glob($this->root . '/*') ?: [], 'nothing is left behind');
        self::assertFileDoesNotExist(dirname($path) . '/evil.csv');
    }

    /**
     * @return iterable<string, array{callable(ZipArchive): void, string}>
     */
    public static function refused(): iterable
    {
        $csv = "\"## Vehicle\"\n\"Name\"\n\"Car\"\n";
        yield 'a path out of the folder' => [self::adding('../evil.csv', $csv), 'import_app.archive.path'];
        yield 'a path deeper out' => [self::adding('a/../../evil.csv', $csv), 'import_app.archive.path'];
        yield 'an absolute path' => [self::adding('/tmp/evil.csv', $csv), 'import_app.archive.path'];
        yield 'a backslash path' => [self::adding('..\\evil.csv', $csv), 'import_app.archive.path'];
        yield 'a drive letter' => [self::adding('C:/evil.csv', $csv), 'import_app.archive.path'];
        yield 'too many entries' => [static function (ZipArchive $z) use ($csv): void {
            for ($i = 0; $i <= ArchiveReader::MAX_ENTRIES; $i++) {
                $z->addFromString("v{$i}.csv", $csv);
            }
        }, 'import_app.archive.too_many'];
        yield 'a nested zip' => [self::adding('inner.zip', self::innerZip(['a.csv' => 'x'])), 'import_app.archive.nested'];
        yield 'a zip by its bytes' => [self::adding('photos.bin', self::innerZip(['a.csv' => 'x'])), 'import_app.archive.nested'];
        yield 'a second level of nesting' => [
            self::adding('pictures.data', self::innerZip(['deeper.zip' => self::innerZip(['x.jpg' => 'x'])])),
            'import_app.archive.nested',
        ];
        yield 'something other than photos inside' => [
            self::adding('pictures.data', self::innerZip(['run.sh' => 'echo'])),
            'import_app.archive.nested',
        ];
        yield 'a compression bomb' => [self::adding('bomb.csv', str_repeat("\0", 8 * 1024 * 1024)), 'import_app.archive.too_big'];
    }

    /**
     * @return callable(ZipArchive): void
     */
    private static function adding(string $name, string $contents): callable
    {
        return static function (ZipArchive $zip) use ($name, $contents): void {
            $zip->addFromString($name, $contents);
        };
    }

    public function testAFileThatIsNoZipIsRefused(): void
    {
        $path = $this->temp('not-a.zip');
        file_put_contents($path, 'just text');

        $this->expectExceptionObject(new ArchiveRefused('import_app.archive.not_zip'));
        (new ArchiveReader($this->root))->open($path);
    }

    /**
     * @param callable(ZipArchive): void $fill
     */
    private function zip(callable $fill): string
    {
        $path = $this->temp('test.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $fill($zip);
        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * @param array<string, string> $files
     */
    private static function innerZip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'inner');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function temp(string $name): string
    {
        $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        $this->made[] = $path;

        return $path;
    }
}
