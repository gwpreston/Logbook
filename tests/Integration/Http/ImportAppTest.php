<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\FuelioCsv;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Slim\Psr7\UploadedFile;

/**
 * Settings → Import from another app (spec.md §7.13, Phase 31): upload a
 * Fuelio CSV, map, preview, import; anything else and backup ZIPs get a
 * clear message; only vehicles the user can manage are targets.
 */
final class ImportAppTest extends AppTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testTheOwnersCsvGoesThroughUploadMapPreviewAndImport(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        self::assertStringContainsString('/settings/import-app', self::body($browser->get('/settings')));

        $csv = (string) file_get_contents(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/export.csv');
        $map = $this->upload($browser, $csv, 'Audi RS3-1-2024-10-02_16-12.csv');
        $mapping = self::body($browser->get($map));
        self::assertStringContainsString('Audi RS3-1-2024-10-02_16-12.csv', $mapping);
        $guess = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        self::assertSame(['new', 'mi', 'l', 'iso', 'maintenance:service', 'expense:parking', 'petrol'], [
            $guess['vehicle'], $guess['distance_unit'], $guess['volume_unit'], $guess['date_order'],
            $guess['cat[1]'], $guess['cat[5]'], $guess['fuel[110]'],
        ]);

        $preview = self::body($browser->get($map . '?' . http_build_query($guess)));
        self::assertStringContainsString('18 to import', $preview);
        self::assertStringContainsString('45 not imported', $preview, 'photos come with the backup');
        self::assertStringContainsString('Fuelio’s own figures agree', $preview);
        self::assertStringContainsString('A new vehicle will be created', $preview);
        self::assertStringContainsString('Import 23 rows', $preview, '18 fill-ups, a service and 4 stations');

        $form = Html::element(Html::document($preview), 'form[method="post"]');
        $result = $browser->post($map, Html::formValues($form));
        self::assertSame(200, $result->getStatusCode(), self::body($result));
        self::assertStringContainsString('23 rows were imported.', self::body($result));

        $vehicles = $this->ownedVehicles($app, $this->ownerUser($app)->id);
        self::assertCount(1, $vehicles);
        self::assertSame('AB12 CDE', $vehicles[0]->data->registration);
        self::assertCount(18, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicles[0]->id));

        // The staged file is gone: the same page again starts over.
        $again = $browser->get($map);
        self::assertSame(303, $again->getStatusCode());
    }

    public function testAnythingElseAndBackupZipsGetAClearMessage(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $plain = $this->file("Date,Odometer\n2026-01-01,100\n", 'mine.csv');
        $other = $browser->post('/settings/import-app', [], ['file' => $plain]);
        self::assertSame(422, $other->getStatusCode());
        self::assertStringContainsString('This doesn’t look like a Fuelio export', self::body($other));

        $zip = (string) file_get_contents(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/backup.fuelio.zip');
        $backup = $browser->post('/settings/import-app', [], ['file' => $this->file($zip, 'backup.fuelio.zip')]);
        self::assertSame(422, $backup->getStatusCode());
        self::assertStringContainsString('php bin/import-app.php', self::body($backup));
        // Renamed, it is still recognised by its bytes.
        $renamed = $browser->post('/settings/import-app', [], ['file' => $this->file($zip, 'backup.csv')]);
        self::assertStringContainsString('php bin/import-app.php', self::body($renamed));
    }

    public function testOnlyVehiclesTheUserCanManageAreTargets(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        unset($owner);
        $golf = $this->service($app, VehicleService::class)->create(
            $this->ownerUser($app),
            new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol, registration: 'GO19 ABC'),
        );
        $member = $this->createMember($app, 'partner');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-10-01T12:00:00Z'));

        $browser = $this->browserFor($app, 'partner');
        $map = $this->upload($browser, (new FuelioCsv())->fill('2026-01-02 08:00', '1000', '40', '60', '1.5')->build(), 'x.csv');
        $mapping = self::body($browser->get($map));
        self::assertStringNotContainsString('GO19 ABC', $mapping, 'a Log share is not a target');

        self::assertSame(404, $browser->get($map . '?vehicle=' . $golf->id)->getStatusCode());
        $post = $browser->post($map, [
            'vehicle' => (string) $golf->id,
            'distance_unit' => 'km',
            'volume_unit' => 'l',
            'date_order' => 'iso',
        ]);
        self::assertSame(404, $post->getStatusCode());
        self::assertSame([], $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testThePagesAreGoneWithTheFuelModule(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $others = array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Fuel));
        $this->service($app, FeatureToggles::class)->save($others);

        self::assertSame(404, $browser->get('/settings/import-app')->getStatusCode());
        self::assertStringNotContainsString('/settings/import-app', self::body($browser->get('/settings')));
    }

    private function upload(TestBrowser $browser, string $csv, string $name): string
    {
        $response = $browser->post('/settings/import-app', [], ['file' => $this->file($csv, $name)]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $response->getHeaderLine('Location');
    }

    private function file(string $contents, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-app-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'text/csv', strlen($contents), UPLOAD_ERR_OK);
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     */
    private function ownerUser(\Slim\App $app): \Logbook\Domain\User\User
    {
        $user = $this->service($app, \Logbook\Repository\UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);

        return $user;
    }
}
