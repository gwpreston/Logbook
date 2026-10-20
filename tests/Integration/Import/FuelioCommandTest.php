<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Import;

use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Service\Import\App\Fuelio\FuelioCommand;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\FuelioCsv;
use ZipArchive;

/**
 * `php bin/import-app.php` (spec.md §7.13): the backup ZIP with its photos,
 * a dry run that writes nothing, and the refusals.
 */
final class FuelioCommandTest extends AppTestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testADryRunPrintsThePreviewAndWritesNothing(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        [$code, $out] = $this->command($app, [self::backup(), '--dry-run']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('vehicle-1-local.csv', $out);
        self::assertStringContainsString("Fuelio's own figures agree", $out);
        self::assertStringContainsString('Fill-ups: 18 to import', $out);
        self::assertStringContainsString('Photos: 45 to import', $out);
        self::assertStringContainsString('Dry run: nothing was written.', $out);
        self::assertSame([], $this->ownedVehicles($app, $owner->id));
    }

    public function testTheBackupImportsWithItsPhotosAndAgainAddsNothing(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        [$code, $out] = $this->command($app, [self::backup()]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Imported 68 row(s)', $out, '18 fill-ups, a service, 4 stations, 45 photos');
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(1, $vehicles);
        self::assertCount(18, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));
        self::assertCount(45, $this->service($app, AttachmentRepository::class)->listForVehicle($vehicles[0]->id));

        // Run again: the vehicle holding these rows is found, and nothing is new.
        [$again, $second] = $this->command($app, [self::backup()]);
        self::assertSame(0, $again, $second);
        self::assertStringContainsString('18 already imported', $second);
        self::assertStringContainsString('Nothing to import', $second);
        self::assertCount(1, $this->ownedVehicles($app, $owner->id));
    }

    public function testAZipWithTwoVehiclesCreatesBothInOneGo(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $first = (new FuelioCsv())->vehicle(['Name' => 'Octavia', 'Plate' => 'OC19 TAV'])
            ->fill('2026-01-02 08:00', '1000', '40', '60', '1.5')
            ->fill('2026-01-09 08:00', '1500', '35', '52.5', '1.5');
        $second = (new FuelioCsv())
            ->vehicle(['Name' => 'Diesel van', 'Make' => 'Ford', 'Model' => 'Transit', 'Plate' => 'TR20 NSI'])
            ->fill('2026-01-03 08:00', '20000', '60', '90', '1.5', ['FuelType' => '210', 'guid' => FuelioCsv::guid('van', 1)]);
        $path = (string) tempnam(sys_get_temp_dir(), 'two');
        $this->temp[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('vehicle-1-local.csv', $first->build());
        $zip->addFromString('vehicle-2-local.csv', $second->build());
        $zip->close();

        [$code, $out] = $this->command($app, [$path]);
        self::assertSame(0, $code, $out);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertSame(['OC19 TAV', 'TR20 NSI'], array_map(static fn ($v): ?string => $v->data->registration, $vehicles));
        $fills = $this->service($app, FuelEntryRepository::class);
        self::assertCount(2, $fills->listForVehicle($vehicles[0]->id));
        self::assertCount(1, $fills->listForVehicle($vehicles[1]->id));

        // --vehicle can't say where two vehicles go.
        self::assertSame(3, $this->command($app, [$path, '--vehicle', (string) $vehicles[0]->id])[0]);
    }

    /**
     * K11 and K12: rows with problems stop the import unless --skip-invalid
     * says otherwise, errors name the file's columns, an unknown fuel code
     * is pointed out, and sections Logbook doesn't read are listed.
     */
    public function testRowsWithProblemsNeedSkipInvalidAndThePreviewSpeaksFuelio(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $csv = (new FuelioCsv())->vehicle(['Name' => 'Octavia', 'Plate' => 'OC19 TAV'])
            ->fill('2026-01-02 08:00', '1000', '40', '60', '1.5')
            ->fill('not a date', '1500', '35', '52.5', '1.5')
            ->fill('2026-01-16 08:00', '2000', '35', '52.5', '1.5', ['FuelType' => '999'])
            ->build() . "\"## Trips\"\n\"Start\",\"End\"\n\"1\",\"2\"\n";
        $path = (string) tempnam(sys_get_temp_dir(), 'fuelio');
        $this->temp[] = $path;
        file_put_contents($path, $csv);

        [$code, $out, $err] = $this->command($app, [$path]);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('--skip-invalid', $err);
        self::assertStringContainsString('nothing was written', $err);
        self::assertSame([], $this->ownedVehicles($app, $owner->id));
        self::assertMatchesRegularExpression('/line \d+, [^:]+: Data: /', $out, 'the column, not filled_at');
        self::assertStringNotContainsString('filled_at', $out);
        self::assertStringContainsString('Fuel type 999: not one Logbook recognises, imported as petrol: check it', $out);
        self::assertStringNotContainsString('Fuel type 110', $out);
        self::assertStringContainsString('Not read from the file: Category, Trips.', $out);

        [$code, $out] = $this->command($app, [$path, '--skip-invalid']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Imported 2 row(s)', $out);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(2, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));

        // K126: run again, only the bad row is left: it still stops the run.
        [$code, $out, $err] = $this->command($app, [$path]);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('no other rows are left to import', $err);
    }

    /**
     * K21: the CSV imported first (on the web page, say), then the backup
     * ZIP brings the photos to those fill-ups, once.
     */
    public function testABackupAfterItsCsvAttachesThePhotosOnce(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $zip = new ZipArchive();
        self::assertTrue($zip->open(self::backup()));
        $csv = (string) tempnam(sys_get_temp_dir(), 'fuelio');
        $this->temp[] = $csv;
        file_put_contents($csv, (string) $zip->getFromName('vehicle-1-local.csv'));
        $zip->close();

        [$code, $out] = $this->command($app, [$csv]);
        self::assertSame(0, $code, $out);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(1, $vehicles);
        $attachments = $this->service($app, AttachmentRepository::class);
        self::assertSame([], $attachments->listForVehicle($vehicles[0]->id));

        [$code, $out] = $this->command($app, [self::backup()]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('18 already imported', $out);
        self::assertStringContainsString('Photos: 45 to import', $out);
        self::assertCount(1, $this->ownedVehicles($app, $owner->id));
        self::assertCount(18, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));
        self::assertCount(45, $attachments->listForVehicle($vehicles[0]->id));

        [$code, $out] = $this->command($app, [self::backup()]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Nothing to import', $out);
        self::assertCount(45, $attachments->listForVehicle($vehicles[0]->id));
    }

    public function testUsageAndRefusals(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        self::assertSame(3, $this->command($app, [])[0]);
        self::assertSame(3, $this->command($app, [self::backup(), '--create', '--vehicle', '1'])[0]);
        self::assertSame(1, $this->command($app, ['/no/such/file.zip'])[0]);
        self::assertSame(1, $this->command($app, [self::backup(), '--vehicle', '999'])[0], 'not a vehicle they can manage');

        $evil = (string) tempnam(sys_get_temp_dir(), 'evil');
        $this->temp[] = $evil;
        $zip = new ZipArchive();
        $zip->open($evil, ZipArchive::OVERWRITE);
        $zip->addFromString('../evil.csv', 'x');
        $zip->close();
        [$code, , $err] = $this->command($app, [$evil]);
        self::assertSame(1, $code);
        self::assertStringContainsString('unsafe name', $err);

        $this->createMember($app, 'partner');
        [$code, , $err] = $this->command($app, [self::backup()]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--as <username>', $err);
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function command(\Slim\App $app, array $args): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $this->service($app, FuelioCommand::class)->run($args, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private static function backup(): string
    {
        return Kernel::rootDir() . '/tests/Fixtures/import/fuelio/backup.fuelio.zip';
    }
}
