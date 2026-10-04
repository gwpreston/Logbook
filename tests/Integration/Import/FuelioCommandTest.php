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
