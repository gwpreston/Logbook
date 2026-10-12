<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Backup;

use Logbook\Service\Backup\UploadSwap;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Storage\FileStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A restore's upload swap (spec.md §7.13 *Restore*, Phase 45.2): applied,
 * undone and finished, with every move checked.
 */
final class UploadSwapTest extends TestCase
{
    private const string OLD = 'attachments/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';
    private const string NEW = 'attachments/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.pdf';

    private string $root;
    private FileStorage $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/logbook-swap-' . bin2hex(random_bytes(6));
        $this->files = new FileStorage(AppSettings::fromEnv(new Env(['UPLOAD_PATH' => $this->root]), '/tmp'));
        $this->put($this->root . '/' . self::OLD, 'old');
        $this->put($this->root . '/pending/cccccccccccccccccccccccccccccccc.jpg', 'a scan');
        $this->put($this->root . '/.restore-x/' . self::NEW, 'new');
    }

    protected function tearDown(): void
    {
        UploadSwap::removeDirectory($this->root);
    }

    public function testAppliedAndFinishedTheArchivesFilesAreAllThatIsLeft(): void
    {
        $swap = new UploadSwap($this->files, $this->root . '/.restore-x');
        $swap->apply();
        self::assertSame([self::NEW], $this->files->all());
        self::assertFileExists($this->root . '/pending/cccccccccccccccccccccccccccccccc.jpg', 'scans go only once committed');

        $swap->finish();
        self::assertSame([self::NEW], $this->files->all());
        self::assertSame([], glob($this->root . '/.re*', GLOB_ONLYDIR) ?: []);
        self::assertFileDoesNotExist($this->root . '/pending/cccccccccccccccccccccccccccccccc.jpg');
    }

    public function testUndoneTheFilesAreAsTheyWere(): void
    {
        $swap = new UploadSwap($this->files, $this->root . '/.restore-x');
        $swap->apply();

        self::assertSame([], $swap->undo());
        self::assertSame([self::OLD], $this->files->all());
        self::assertSame('old', file_get_contents($this->root . '/' . self::OLD));
        self::assertSame([], glob($this->root . '/.re*', GLOB_ONLYDIR) ?: []);
        self::assertSame([], $swap->undo(), 'twice is harmless');
    }

    public function testAMoveThatFailsIsUndoneAndThrown(): void
    {
        mkdir($this->root . '/' . self::NEW);
        $swap = new UploadSwap($this->files, $this->root . '/.restore-x');

        try {
            $swap->apply();
            self::fail('applied');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Cannot move', $e->getMessage());
        }
        self::assertSame([self::OLD], $this->files->all());
    }

    public function testAFileThatCannotBePutBackIsNamedAndItsCopyKept(): void
    {
        $swap = new UploadSwap($this->files, $this->root . '/.restore-x');
        $swap->apply();
        // Something takes the old file's place meanwhile.
        unlink($this->root . '/' . self::NEW);
        mkdir($this->root . '/' . self::OLD);

        self::assertSame([self::OLD], $swap->undo());
        $kept = glob($this->root . '/.replaced-*/' . self::OLD) ?: [];
        self::assertCount(1, $kept, 'the copy set aside stays for the owner');
    }

    private function put(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
