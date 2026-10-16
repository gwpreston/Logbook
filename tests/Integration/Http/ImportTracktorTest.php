<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Settings → Import from another app with a Tracktor export (spec.md §7.13
 * *The Tracktor reader*, Phase 51): upload the JSON and its uploads ZIP,
 * map, preview every vehicle, import; a password-protected or tampered
 * file is refused with nothing written; only vehicles the user can manage
 * are targets.
 */
final class ImportTracktorTest extends AppTestCase
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

    public function testAGarageGoesThroughUploadMapPreviewAndImportWithItsFiles(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $upload = self::body($browser->get('/settings/import-app'));
        self::assertStringContainsString('Exporting from Tracktor', $upload);
        self::assertStringContainsString('name="files"', $upload);

        $json = self::fixture('export-metric.json');
        $map = $this->upload($browser, $json, 'tracktor-export-2026-10-16.json', self::fixture('uploads.zip'));
        $mapping = self::body($browser->get($map));
        self::assertStringContainsString('From Tracktor', $mapping);
        self::assertStringContainsString('2 vehicles in the file', $mapping);
        self::assertStringContainsString('With Tracktor’s uploads: uploads.zip', $mapping);
        self::assertStringContainsString('Not read: notifications, notificationProviders.', $mapping);
        $guess = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        self::assertSame(['km', 'l', 'service', 'pollution', 'inspection', '1', ''], [
            $guess['distance_unit'], $guess['volume_unit'], $guess['service'],
            $guess['doc[emissions]'], $guess['doc[roadworthiness]'], $guess['reminders'], $guess['schedules'] ?? '',
        ]);

        $preview = self::body($browser->get($map . '?' . http_build_query($guess)));
        self::assertStringContainsString('Maruti Suzuki Swift KA01AB1234', $preview);
        self::assertStringContainsString('Royal Enfield Classic 350 KA05XY9876', $preview);
        self::assertStringContainsString('A Logbook vehicle has no place for it.', $preview, 'the colour and custom fields');
        self::assertStringContainsString('Completed in Tracktor: marked done.', $preview);
        self::assertStringNotContainsString('stay behind', $preview, 'the uploads came with it');
        // 8 + 3 + 3 + 2 + 1 + 4 for the car, 3 + 1 + 1 + 1 + 1 for the bike.
        self::assertStringContainsString('Import 28 rows', $preview);

        $form = Html::element(Html::document($preview), 'form[method="post"]');
        $result = $browser->post($map, Html::formValues($form));
        self::assertSame(200, $result->getStatusCode(), self::body($result));
        $body = self::body($result);
        self::assertStringContainsString('Imported from Tracktor', $body);
        self::assertStringContainsString('28 rows were imported.', $body);

        $vehicles = $this->ownedVehicles($app, $this->owner($app)->id);
        self::assertCount(2, $vehicles);
        $swift = $vehicles[0]->data->registration === 'KA01AB1234' ? $vehicles[0] : $vehicles[1];
        self::assertCount(8, $this->service($app, FuelEntryRepository::class)->listForVehicle($swift->id));
        self::assertCount(3, $this->service($app, AttachmentRepository::class)->listForVehicle($swift->id));

        self::assertSame(303, $browser->get($map)->getStatusCode(), 'the staged files are gone');
    }

    public function testWithoutTheUploadsThePreviewSaysWhatStaysBehind(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $map = $this->upload($browser, self::fixture('export-metric.json'), 'export.json');

        $preview = self::body($browser->get($map . '?vehicle[x]=new'));
        self::assertStringContainsString('4 files stay behind', $preview);
        self::assertStringContainsString('Tracktor’s uploads folder wasn’t given.', $preview);
    }

    public function testRefusedFilesSayWhyAndWriteNothing(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $protected = $this->file(self::fixture('export-encrypted.json'), 'e.json');
        $encrypted = $browser->post('/settings/import-app', [], ['file' => $protected]);
        self::assertSame(422, $encrypted->getStatusCode());
        self::assertStringContainsString(
            'This export is password-protected. Export again from Tracktor without a password.',
            self::body($encrypted),
        );

        $tampered = str_replace('"version": "1.0"', '"version": "9.9"', self::fixture('export-metric.json'));
        $other = $browser->post('/settings/import-app', [], ['file' => $this->file($tampered, 't.json')]);
        self::assertSame(422, $other->getStatusCode());
        self::assertStringContainsString('format 9.9', self::body($other));

        $notZip = $browser->post('/settings/import-app', [], [
            'file' => $this->file(self::fixture('export-metric.json'), 'export.json'),
            'files' => $this->file('not a zip', 'uploads.zip'),
        ]);
        self::assertSame(422, $notZip->getStatusCode());
        self::assertStringContainsString('Tracktor’s uploads folder goes in as a ZIP file.', self::body($notZip));

        $fuelio = (string) file_get_contents(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/export.csv');
        $mixed = $browser->post('/settings/import-app', [], [
            'file' => $this->file($fuelio, 'fuelio.csv'),
            'files' => $this->file(self::fixture('uploads.zip'), 'uploads.zip'),
        ]);
        self::assertSame(422, $mixed->getStatusCode());
        self::assertStringContainsString('The uploads folder is only read with a Tracktor export.', self::body($mixed));

        self::assertSame([], $this->ownedVehicles($app, $this->owner($app)->id));
    }

    public function testInvalidRowsNeedSkippingAndASecondImportHasNothingLeft(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $json = (string) json_encode(['version' => '1.0', 'encrypted' => false, 'data' => [
            'vehicles' => [['id' => 'v1', 'make' => 'Skoda', 'model' => 'Octavia', 'vehicleType' => 'car']],
            'fuelLogs' => [
                ['id' => 'f1', 'vehicleId' => 'v1', 'date' => '2026-05-01T00:00:00.000Z', 'odometer' => 1000,
                    'fuelAmount' => 40, 'rate' => 1.5, 'cost' => 60, 'filled' => true],
                ['id' => 'f2', 'vehicleId' => 'v1', 'date' => '2026-05-20T00:00:00.000Z', 'odometer' => null,
                    'fuelAmount' => 40, 'rate' => 1.5, 'cost' => 60, 'filled' => true],
            ],
        ]]);
        $map = $this->upload($browser, $json, 'one.json');
        $values = ['vehicle' => ['v1' => 'new'], 'distance_unit' => 'km', 'volume_unit' => 'l', 'reminders' => '1'];

        $refused = $browser->post($map, $values);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Skip the row with problems', self::body($refused));
        self::assertSame([], $this->ownedVehicles($app, $this->owner($app)->id));

        $done = $browser->post($map, $values + ['skip_invalid' => '1']);
        self::assertSame(200, $done->getStatusCode(), self::body($done));
        self::assertStringContainsString('1 row was imported.', self::body($done));

        // The same file again: matched by Tracktor's ids, nothing left to import.
        $again = $this->upload($browser, $json, 'one.json');
        $vehicle = $this->ownedVehicles($app, $this->owner($app)->id)[0];
        $nothing = $browser->post($again, ['vehicle' => ['v1' => (string) $vehicle->id]] + $values + ['skip_invalid' => '1']);
        self::assertSame(422, $nothing->getStatusCode());
        self::assertStringContainsString('Nothing to import', self::body($nothing));

        // A staged file that has gone starts over.
        $this->upload($browser, $json, 'other.json');
        self::assertSame(303, $browser->get($again)->getStatusCode());
    }

    public function testWorksBehindASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $browser = $this->signedIn($app);
        $response = $browser->post('/logbook/settings/import-app', [], [
            'file' => $this->file(self::fixture('export-1.4.1.json'), 'export.json'),
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $map = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/logbook/settings/import-app/', $map);

        // Hard refresh with the prefix stripped by the proxy.
        $page = self::body($browser->get(substr($map, strlen('/logbook')) . '?vehicle[x]=new'));
        self::assertStringContainsString('action="' . $map . '"', $page);
        self::assertStringContainsString('href="/logbook/settings/import-app"', $page);
        $form = Html::element(Html::document($page), 'form[method="post"]:not([action$="/theme"])');
        $result = $browser->post($map, Html::formValues($form));
        self::assertSame(200, $result->getStatusCode(), self::body($result));
        self::assertStringContainsString('href="/logbook/garage"', self::body($result));
    }

    public function testOnlyVehiclesTheUserCanManageAreTargets(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->service($app, VehicleService::class)->create(
            $this->owner($app),
            new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol, registration: 'GO19 ABC'),
        );
        $member = $this->createMember($app, 'partner');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-10-01T12:00:00Z'));

        $browser = $this->browserFor($app, 'partner');
        $map = $this->upload($browser, self::fixture('export-imperial.json'), 'export.json');
        self::assertStringNotContainsString('GO19 ABC', self::body($browser->get($map)), 'a Log share is not a target');

        /** @var array{data: array{vehicles: list<array{id: string}>}} $json */
        $json = json_decode(self::fixture('export-imperial.json'), true);
        $first = $json['data']['vehicles'][0]['id'];
        $query = http_build_query(['vehicle' => [$first => (string) $golf->id]]);
        self::assertSame(404, $browser->get($map . '?' . $query)->getStatusCode());
        self::assertSame(404, $browser->post($map, ['vehicle' => [$first => (string) $golf->id]])->getStatusCode());
        self::assertSame([], $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));
    }

    private function upload(TestBrowser $browser, string $json, string $name, ?string $zip = null): string
    {
        $files = ['file' => $this->file($json, $name)];
        if ($zip !== null) {
            $files['files'] = $this->file($zip, 'uploads.zip');
        }
        $response = $browser->post('/settings/import-app', [], $files);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $response->getHeaderLine('Location');
    }

    private function file(string $contents, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-tracktor-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'application/octet-stream', strlen($contents), UPLOAD_ERR_OK);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(Kernel::rootDir() . '/tests/Fixtures/import/tracktor/' . $name);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function owner(App $app): User
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($user);

        return $user;
    }
}
