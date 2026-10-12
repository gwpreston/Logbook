<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Kernel;
use Logbook\Repository\BackupRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Jobs\BackupJob;
use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Household\InstanceSnapshot;
use Logbook\Tests\Support\Household\LeakSweeping;
use Logbook\Tests\Support\MutableClock;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * The restore matrix (Phase 45.2, spec.md §7.13): the golden household,
 * with its uploads and shares, backed up and restored, then compared row
 * by row, file by file and figure by figure (InstanceSnapshot).
 *
 * On every engine, every push: a round trip through the pages, and through
 * `bin/backup.php` and the scheduled backup with its retention.
 *
 * Across engines (#416): set RESTORE_MATRIX_OUT to a directory and each run
 * leaves its engine's backup and snapshot there; set RESTORE_MATRIX_IN to
 * such a directory and each backup in it is restored on this engine and
 * compared with its source's snapshot. CI does SQLite → PostgreSQL on every
 * push and every pair nightly (`restore-matrix.yml`).
 */
final class HouseholdRestoreTest extends AppTestCase
{
    use LeakSweeping;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/logbook-restore-' . bin2hex(random_bytes(6));
        mkdir($this->backupDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->backupDir);
        parent::tearDown();
    }

    public function testTheHouseholdRestoresExactlyThroughThePages(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $household = $this->loadHousehold($app);
        $before = InstanceSnapshot::take($app->getContainer());
        self::assertCount(7, $before->files, 'the receipts and certificates');
        self::assertCount(5, $before->tables['users']);
        self::assertCount(4, $before->tables['vehicle_shares']);

        $alex = $this->browserFor($app, 'alex');
        $download = $alex->get('/settings/backup/download');
        self::assertSame(200, $download->getStatusCode());
        $backup = $this->backupDir . '/household.zip';
        file_put_contents($backup, self::body($download));
        $this->leaveForOtherEngines($app, $backup, $before);

        // Then things change: an expense, a file gone.
        $this->spend($app, $household, '41.00');
        $storage = $this->service($app, FileStorage::class);
        $storage->delete($storage->all()[0]);

        $alex->get('/settings/backup');
        $upload = $alex->post('/settings/backup/restore', [], ['backup' => new UploadedFile(
            $backup,
            'household.zip',
            'application/zip',
            (int) filesize($backup),
            UPLOAD_ERR_OK,
        )]);
        self::assertSame(303, $upload->getStatusCode(), self::body($upload));
        $confirm = $upload->getHeaderLine('Location');
        $alex->get($confirm);
        $done = $alex->post($confirm, ['confirm' => '1']);
        self::assertSame(303, $done->getStatusCode(), self::body($done));

        $after = InstanceSnapshot::take($app->getContainer());
        self::assertSame([], $before->differences($after), 'every row, file and figure as it was');
        self::assertSame([], self::againstExpected($after), 'and the owner\'s expected figures, where filled in');
        $safety = glob($this->backupDir . '/pre-restore-*.zip') ?: [];
        self::assertCount(1, $safety, 'what was there before was kept');
        self::assertSame(
            count($before->tables['expense_entries']) + 1,
            $this->service($app, BackupService::class)->inspect($safety[0])->rows('expense_entries'),
        );
    }

    public function testBackupsFromOtherEnginesRestoreExactly(): void
    {
        $dir = getenv('RESTORE_MATRIX_IN');
        if (!is_string($dir) || $dir === '') {
            self::markTestSkipped('Set RESTORE_MATRIX_IN to a directory of backups made by RESTORE_MATRIX_OUT.');
        }
        $backups = glob(rtrim($dir, '/') . '/*.zip') ?: [];
        self::assertNotEmpty($backups, "no backups in {$dir}");

        $differences = [];
        foreach ($backups as $backup) {
            $source = InstanceSnapshot::fromJson((string) file_get_contents(substr($backup, 0, -4) . '.json'));
            $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
            $this->pinClock($app, InputBuilder::TODAY_UTC);
            $this->resetDatabase($app);
            $this->service($app, BackupService::class)->restore($backup);
            $after = InstanceSnapshot::take($app->getContainer());
            foreach ($source->differences($after) as $line) {
                $differences[] = basename($backup) . ' on ' . self::engine($app) . ': ' . $line;
            }
            self::assertSame([], self::againstExpected($after));
        }

        self::assertSame([], $differences, implode("\n", $differences));
    }

    public function testTheCommandLineAndTheScheduledBackupRestoreExactly(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $household = $this->loadHousehold($app);
        // Three scheduled runs, keeping two: the oldest goes.
        $this->service($app, JobSettings::class)->setBackup(BackupSchedule::Daily, 2);
        $before = InstanceSnapshot::take($app->getContainer());

        $cli = $this->backupDir . '/by-hand.zip';
        [$code, $output] = $this->command(['create', $cli]);
        self::assertSame(0, $code, $output);

        $clock = $this->service($app, ClockInterface::class);
        self::assertInstanceOf(MutableClock::class, $clock);
        foreach (['2026-09-15T03:00:00Z', '2026-09-16T03:00:00Z', '2026-09-17T03:00:00Z'] as $at) {
            $clock->set(new DateTimeImmutable($at));
            $job = $this->service($app, JobRegistry::class)->get($this->service($app, BackupJob::class)->name());
            self::assertNotNull($job);
            $this->service($app, JobRunner::class)->run($job, JobTrigger::Manual, null);
        }
        $scheduled = glob($this->backupDir . '/logbook-scheduled-*.zip') ?: [];
        sort($scheduled);
        self::assertSame(
            ['logbook-scheduled-20260916-030000.zip', 'logbook-scheduled-20260917-030000.zip'],
            array_map('basename', $scheduled),
            'two kept, the oldest deleted',
        );
        $clock->set(new DateTimeImmutable(InputBuilder::TODAY_UTC));

        $restores = ['the command line' => [$cli, true], 'the scheduled backup' => [$scheduled[1], false]];
        foreach ($restores as $what => [$file, $byCommand]) {
            $this->spend($app, $household, '12.00');
            $this->emptyInstance($app);
            if ($byCommand) {
                [$code, $output] = $this->command(['restore', $file, '--yes']);
                self::assertSame(0, $code, $output);
            } else {
                $this->service($app, BackupService::class)->restore($file);
            }
            self::assertSame([], $before->differences(InstanceSnapshot::take($app->getContainer())), $what);
        }
    }

    /**
     * Leave this engine's backup and snapshot for another engine to restore
     * (RESTORE_MATRIX_OUT), named by the engine.
     *
     * @param App<ContainerInterface> $app
     */
    private function leaveForOtherEngines(App $app, string $backup, InstanceSnapshot $snapshot): void
    {
        $dir = getenv('RESTORE_MATRIX_OUT');
        if (!is_string($dir) || $dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $engine = self::engine($app);
        copy($backup, $dir . '/' . $engine . '.zip');
        file_put_contents($dir . '/' . $engine . '.json', $snapshot->toJson());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function engine(App $app): string
    {
        $connection = $app->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $server = $connection->getServerVersion();

        return match (true) {
            $connection->getDatabasePlatform() instanceof SQLitePlatform => 'sqlite',
            $connection->getDatabasePlatform() instanceof PostgreSQLPlatform => 'pgsql',
            stripos($server, 'mariadb') !== false => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * The owner's filled-in expected figures (expected.csv) that the
     * snapshot's figures differ from; none while it is empty.
     *
     * @return list<string>
     */
    private static function againstExpected(InstanceSnapshot $snapshot): array
    {
        $wrong = [];
        foreach (ExpectedSkeleton::read(dirname(GoldenHousehold::INPUTS) . '/expected.csv') as $row) {
            $expected = str_replace([',', '−'], ['', '-'], trim($row['expected']));
            if ($expected === '') {
                continue;
            }
            $actual = str_replace([',', '−'], ['', '-'], $snapshot->figures[$row['id']] ?? '');
            $same = is_numeric($expected) && is_numeric($actual)
                ? BigDecimal::of($expected)->isEqualTo($actual)
                : strtolower($expected) === strtolower($actual);
            if (!$same) {
                $wrong[] = "row {$row['id']}: expected {$row['expected']}, restored {$actual}";
            }
        }

        return $wrong;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function spend(App $app, Household $household, string $amount): void
    {
        $this->service($app, AccessContext::class)->apply($household->user('alex'));
        $this->service($app, ExpenseService::class)->create($household->vehicle('golf'), new ExpenseEntryData(
            new DateTimeImmutable('2026-09-14', new DateTimeZone('UTC')),
            ExpenseCategory::Parking,
            $amount,
            'After the backup',
        ));
        $this->service($app, AccessContext::class)->apply(null);
    }

    /**
     * No data and no files, as on a new install.
     *
     * @param App<ContainerInterface> $app
     */
    private function emptyInstance(App $app): void
    {
        $this->resetDatabase($app);
        $storage = $this->service($app, FileStorage::class);
        foreach ($storage->all() as $relative) {
            $storage->delete($relative);
        }
    }

    /**
     * Run bin/backup.php against this test's database, uploads and backups.
     *
     * @param list<string> $args
     * @return array{int, string} exit code, output
     */
    private function command(array $args): array
    {
        $env = getenv() + [];
        $env['APP_ENV'] = 'testing';
        $env['UPLOAD_PATH'] = $this->uploadDir();
        $env['BACKUP_PATH'] = $this->backupDir;
        $process = proc_open(
            [PHP_BINARY, Kernel::rootDir() . '/bin/backup.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Kernel::rootDir(),
            $env,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}
