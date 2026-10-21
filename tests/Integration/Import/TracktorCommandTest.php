<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Import;

use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ImportAppCommand;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use ZipArchive;

/**
 * `php bin/import-app.php <export.json>` (spec.md §7.13 *The Tracktor
 * reader*): a dry run, the uploads as a folder or a ZIP, a second run that
 * adds nothing, and the refusals. The command picks the reader by the file.
 */
final class TracktorCommandTest extends AppTestCase
{
    private ?string $folder = null;

    protected function tearDown(): void
    {
        if ($this->folder !== null) {
            ArchiveReader::removeDirectory($this->folder);
        }
        parent::tearDown();
    }

    public function testADryRunPrintsEveryVehicleAndWritesNothing(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        [$code, $out, $err] = $this->command($app, [self::path('export-metric.json'), '--dry-run']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('export-metric.json: 2 vehicle(s); units km and l.', $out);
        self::assertStringContainsString('Not read: notifications, notificationProviders', $out);
        self::assertStringContainsString('Maruti Suzuki Swift KA01AB1234: a new vehicle', $out);
        self::assertStringContainsString('Fill-ups: 8 to import', $out);
        self::assertStringContainsString('Files: 4 not imported', $out);
        self::assertStringContainsString('Tracktor’s uploads folder wasn’t given.', $out);
        self::assertStringContainsString('Dry run: nothing was written.', $out);
        self::assertSame([], $this->ownedVehicles($app, $owner->id));
    }

    public function testTheUploadsFolderBringsTheFilesAndASecondRunAddsNothing(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $folder = $this->uploadsFolder();

        [$code, $out, $err] = $this->command($app, [self::path('export-metric.json'), '--files', $folder]);
        self::assertSame(0, $code, $err . $out);
        self::assertStringContainsString('Imported 21 row(s) into Maruti Suzuki Swift', $out, '8 + 3 + 3 + 2 + 1 + 4');
        self::assertStringContainsString('Imported 7 row(s) into Royal Enfield Classic 350', $out);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(2, $vehicles);
        $attachments = 0;
        foreach ($vehicles as $vehicle) {
            $attachments += count($this->service($app, AttachmentRepository::class)->listForVehicle($vehicle->id));
        }
        self::assertSame(3, $attachments);
        self::assertFileExists($folder . '/1792132540114-policy.pdf', 'the folder is only read');

        [$again, $second] = $this->command($app, [self::path('export-metric.json'), '--files', self::path('uploads.zip')]);
        self::assertSame(0, $again, $second);
        self::assertStringContainsString('into Maruti Suzuki Swift', $second, 'matched by Tracktor\'s ids');
        self::assertStringContainsString('8 already imported', $second);
        self::assertStringContainsString('Nothing to import', $second);
        self::assertCount(2, $this->ownedVehicles($app, $owner->id));
    }

    public function testCreateAndSchedulesWithTheOneVehicleOf1x(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        [$code, $out, $err] = $this->command($app, [self::path('export-1.4.1.json'), '--create', '--schedules']);
        self::assertSame(0, $code, $err . $out);
        self::assertStringContainsString('Not read: users, sessions', $out);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(1, $vehicles);
        self::assertCount(3, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));
    }

    public function testAFuelioExportStillGoesToFuelio(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $csv = Kernel::rootDir() . '/tests/Fixtures/import/fuelio/export.csv';
        [$code, $out, $err] = $this->command($app, ['--as', 'owner', $csv, '--dry-run']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("Fuelio's own figures agree", $out);
    }

    /**
     * K65: rows with problems stop the import unless --skip-invalid says
     * otherwise, and errors name the export's fields, as for Fuelio (K11).
     */
    public function testRowsWithProblemsNeedSkipInvalidAndNameTheExportsFields(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        // The second of its three fill-ups loses its date.
        $json = preg_replace(
            '/"date":\s*"2026-02-05T00:00:00.000Z"/',
            '"date": null',
            (string) file_get_contents(self::path('export-1.4.1.json')),
            1,
            $count,
        );
        self::assertSame(1, $count);
        $path = (string) tempnam(sys_get_temp_dir(), 'tracktor');
        file_put_contents($path, (string) $json);
        rename($path, $path . '.json');
        $path .= '.json';

        try {
            [$code, $out, $err] = $this->command($app, [$path, '--create']);
            self::assertSame(1, $code, $out . $err);
            self::assertStringContainsString('--skip-invalid', $err);
            self::assertStringContainsString('nothing was written', $err);
            self::assertSame([], $this->ownedVehicles($app, $owner->id));
            self::assertMatchesRegularExpression('/row \d+, [^:]+: date: /', $out, 'the export\'s field, not filled_at');
            self::assertStringNotContainsString('filled_at', $out);

            [$code, $out, $err] = $this->command($app, [$path, '--create', '--skip-invalid']);
            self::assertSame(0, $code, $out . $err);
            $vehicles = $this->ownedVehicles($app, $owner->id);
            self::assertCount(1, $vehicles);
            self::assertCount(2, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));

            // K126: run again, only the bad row is left: it still stops the run.
            [$code, $out, $err] = $this->command($app, [$path]);
            self::assertSame(1, $code, $out . $err);
            self::assertStringContainsString('no other rows are left to import', $err);
            [$code, $out] = $this->command($app, [$path, '--skip-invalid']);
            self::assertSame(0, $code, $out);
            self::assertStringContainsString('Nothing to import', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testRefusalsSayWhyAndWriteNothing(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        [$code, , $err] = $this->command($app, [self::path('export-encrypted.json')]);
        self::assertSame(1, $code);
        self::assertStringContainsString('password-protected', $err);

        [$code, , $err] = $this->command($app, [self::path('export-metric.json'), '--vehicle', '1']);
        self::assertSame(3, $code);
        self::assertStringContainsString('--vehicle needs an export of one vehicle; this one has 2.', $err);

        $nested = (string) tempnam(sys_get_temp_dir(), 'logbook-nested-');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($nested, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('uploads/inner.zip', "PK\x03\x04rest");
        $zip->close();
        [$code, , $err] = $this->command($app, [self::path('export-metric.json'), '--files', $nested]);
        @unlink($nested);
        self::assertSame(1, $code);
        self::assertStringContainsString('Zip Tracktor’s uploads folder itself.', $err);

        [$code, , $err] = $this->command($app, ['--files']);
        self::assertSame(3, $code);
        self::assertStringContainsString('<export.json>', $err);
        self::assertSame([], $this->ownedVehicles($app, $owner->id));
    }

    /** K127: before first-run setup, say so rather than asking for --as. */
    public function testAnInstallWithNoUserYetSaysToFinishSetupFirst(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);

        [$code, $out, $err] = $this->command($app, [self::path('export-metric.json'), '--dry-run']);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertSame(ImportAppCommand::NO_USER_YET, $err);

        // Stopped before the export is read: a missing one isn't reported.
        self::assertSame([1, '', ImportAppCommand::NO_USER_YET], $this->command($app, ['/no/such/export.json']));
        self::assertSame(0, (int) $this->connection($app)->fetchOne('SELECT COUNT(*) FROM vehicles'));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function command(App $app, array $args): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $this->service($app, ImportAppCommand::class)->run($args, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /**
     * Tracktor's uploads folder, as the ZIP fixture holds it.
     */
    private function uploadsFolder(): string
    {
        $this->folder = sys_get_temp_dir() . '/logbook-tracktor-uploads-' . bin2hex(random_bytes(6));
        mkdir($this->folder, 0700, true);
        $zip = new ZipArchive();
        self::assertTrue($zip->open(self::path('uploads.zip')));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            file_put_contents($this->folder . '/' . basename($name), (string) $zip->getFromIndex($i));
        }
        $zip->close();

        return $this->folder;
    }

    private static function path(string $name): string
    {
        return Kernel::rootDir() . '/tests/Fixtures/import/tracktor/' . $name;
    }
}
