<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Backup\InvalidBackup;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Household\InstanceSnapshot;
use Logbook\Tests\Support\Household\LeakSweeping;
use Psr\Container\ContainerInterface;
use Slim\App;
use Throwable;
use ZipArchive;

/**
 * A restore that fails changes nothing (Phase 45.2, spec.md §7.13
 * *Restore*), on every engine, with the golden household as the data.
 *
 * Anything wrong with the archive is found before the pre-restore backup
 * is written, so there is none. A failure after it (a table that can't be
 * written, a file that can't be moved into place) rolls the data back,
 * puts the files back, and leaves the pre-restore backup there.
 */
final class HouseholdRestoreFailureTest extends AppTestCase
{
    use LeakSweeping;

    private string $backupDir;
    /** @var App<ContainerInterface> */
    private App $app;
    private Household $household;
    private string $good;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/logbook-restore-' . bin2hex(random_bytes(6));
        mkdir($this->backupDir);
        $this->app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $this->household = $this->loadHousehold($this->app);
        $this->good = $this->backupDir . '/good.zip';
        $this->service($this->app, BackupService::class)->create($this->good);
        // Then things change, so the backup is not what is there now.
        $this->spend('41.00');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->backupDir);
        parent::tearDown();
    }

    public function testAnArchiveWithSomethingWrongChangesNothingAndIsFoundFirst(): void
    {
        $cases = [
            'a truncated archive' => $this->truncated(),
            'a wrong schema version' => $this->variant(static function (ZipArchive $zip): void {
                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
                assert(is_array($manifest));
                $manifest['schema_version'] = '20200101000000';
                $zip->addFromString('manifest.json', (string) json_encode($manifest));
            }),
            'an unknown column' => $this->variant(static function (ZipArchive $zip): void {
                $table = json_decode((string) $zip->getFromName('database/fuel_entries.json'), true);
                assert(is_array($table) && is_array($table['columns']));
                $table['columns'][1] = 'nonsense';
                $zip->addFromString('database/fuel_entries.json', (string) json_encode($table));
            }),
            'an unsafe file name' => $this->variant(
                static fn (ZipArchive $zip) => $zip->addFromString('uploads/../../evil.php', '<?php'),
            ),
        ];
        $before = InstanceSnapshot::take($this->container());

        foreach ($cases as $case => $file) {
            try {
                $this->service($this->app, BackupService::class)->restore($file);
                self::fail("{$case} was restored");
            } catch (InvalidBackup) {
            }
        }

        self::assertSame([], $before->differences(InstanceSnapshot::take($this->container())));
        self::assertSame([], glob($this->backupDir . '/pre-restore-*.zip') ?: [], 'found before anything was written');
    }

    public function testATableThatCannotBeWrittenRollsEverythingBack(): void
    {
        // Two expenses with one id: valid as an archive, refused by the database half-way through.
        $file = $this->variant(static function (ZipArchive $zip): void {
            $table = json_decode((string) $zip->getFromName('database/expense_entries.json'), true);
            assert(is_array($table) && is_array($table['rows']) && is_array($table['rows'][0]));
            $table['rows'][1] = $table['rows'][0];
            $zip->addFromString('database/expense_entries.json', (string) json_encode($table));
        });
        $this->failsAndChangesNothing($file);
    }

    public function testAFileThatCannotBeMovedIntoPlaceRollsEverythingBack(): void
    {
        // Where the archive puts a file, there is a directory: the move fails part-way.
        $storage = $this->service($this->app, FileStorage::class);
        $victim = $storage->all()[3];
        $storage->delete($victim);
        mkdir($storage->absolutePath($victim));
        file_put_contents($storage->absolutePath($victim) . '/in-the-way', 'x');

        $this->failsAndChangesNothing($this->good);
    }

    private function failsAndChangesNothing(string $file): void
    {
        $before = InstanceSnapshot::take($this->container());
        try {
            $this->service($this->app, BackupService::class)->restore($file);
            self::fail('restored');
        } catch (InvalidBackup $e) {
            self::fail('refused as an archive: ' . $e->getMessage());
        } catch (Throwable) {
        }

        self::assertSame([], $before->differences(InstanceSnapshot::take($this->container())), 'nothing changed');
        $safety = glob($this->backupDir . '/pre-restore-*.zip') ?: [];
        self::assertCount(1, $safety, 'the pre-restore backup is there');
        self::assertSame(
            count($before->tables['expense_entries']),
            $this->service($this->app, BackupService::class)->inspect($safety[0])->rows('expense_entries'),
        );
        self::assertSame([], glob($this->service($this->app, FileStorage::class)->root() . '/.re*') ?: [], 'nothing left over');
        // New rows still get new ids: PostgreSQL's sequences are past the ids there now.
        $this->spend('2.50');
    }

    private function spend(string $amount): void
    {
        $this->service($this->app, AccessContext::class)->apply($this->household->user('alex'));
        $this->service($this->app, ExpenseService::class)->create($this->household->vehicle('golf'), new ExpenseEntryData(
            new DateTimeImmutable('2026-09-14', new DateTimeZone('UTC')),
            ExpenseCategory::Parking,
            $amount,
            'After the backup',
        ));
        $this->service($this->app, AccessContext::class)->apply(null);
    }

    private function container(): ContainerInterface
    {
        return $this->app->getContainer();
    }

    private function truncated(): string
    {
        $copy = $this->backupDir . '/truncated.zip';
        $bytes = (string) file_get_contents($this->good);
        file_put_contents($copy, substr($bytes, 0, intdiv(strlen($bytes), 2)));

        return $copy;
    }

    /**
     * A copy of the good backup, changed.
     *
     * @param callable(ZipArchive): mixed $change
     */
    private function variant(callable $change): string
    {
        $copy = $this->backupDir . '/variant-' . bin2hex(random_bytes(4)) . '.zip';
        copy($this->good, $copy);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($copy));
        $change($zip);
        $zip->close();

        return $copy;
    }
}
