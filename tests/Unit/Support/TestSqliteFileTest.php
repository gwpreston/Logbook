<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Tests\Support\TestSqliteFile;
use PHPUnit\Framework\TestCase;

/**
 * A test run that can't delete its SQLite file stops and says why, instead
 * of testing on the old file (spec.md §9 *Test suite only*, K206, #626).
 */
final class TestSqliteFileTest extends TestCase
{
    public function testAFileThatCannotBeDeletedIsNamedInTheRefusal(): void
    {
        $file = $this->file();

        $refusal = TestSqliteFile::delete($file, static fn (string $path): bool => false);

        self::assertSame(
            "Can't delete {$file}: another process has it open. Close it (a leftover bin/ script"
            . ' or a run in the dev container) and run the tests again.',
            $refusal,
        );
        self::assertFileExists($file);
    }

    public function testAReplacedFileLetsTheRunGoOnWithAnEmptyDatabase(): void
    {
        $file = $this->file();
        file_put_contents($file, 'the last run');

        self::assertNull(TestSqliteFile::delete($file));
        self::assertSame('', file_get_contents($file), 'an empty file is a fresh SQLite database');
    }

    public function testNoFileYetIsNothingToDelete(): void
    {
        $deleted = false;
        $delete = static function (string $path) use (&$deleted): bool {
            $deleted = true;

            return false;
        };

        self::assertNull(TestSqliteFile::delete(sys_get_temp_dir() . '/logbook-no-such.sqlite', $delete));
        self::assertFalse($deleted, 'not even tried');
    }

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function file(): string
    {
        return $this->file = (string) tempnam(sys_get_temp_dir(), 'logbook-k206-');
    }
}
