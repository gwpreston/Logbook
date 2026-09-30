<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;

/**
 * Drop zones (spec.md §7.12, Phase 21.1): every file input is rendered as
 * the plain input inside a `[data-file-drop]` wrapper, with the strings and
 * limit js/file-drop.js needs. The input itself is unchanged, so without JS
 * (and on the server) nothing differs; the upload tests cover the rest.
 */
final class FileDropTest extends AppTestCase
{
    use CostFixtures;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/logbook-drop-backups-' . bin2hex(random_bytes(4));
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

    public function testEveryFileInputIsWrappedInADropZone(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir, 'MAX_UPLOAD_MB' => '8']);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id;

        /** @var array<string, array{string, bool, string, string|null}> $inputs path → [input name, several files?, the wrong-type message, size limit] */
        $inputs = [
            $base . '/fuel/new' => ['attachments[]', true, 'receipt.heic: not a PDF, JPEG, PNG or WebP file', '8'],
            $base . '/edit' => ['photo', false, 'receipt.heic: not a JPEG, PNG or WebP image', '8'],
            $base . '/import/fuel' => ['file', false, 'receipt.heic: not a CSV file', '8'],
            '/settings/backup' => ['backup', false, 'receipt.heic: not a ZIP file', null],
        ];
        foreach ($inputs as $path => [$name, $multiple, $typeMessage, $maxMb]) {
            $response = $browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $document = Html::document(self::body($response));
            $input = Html::element($document, 'input[type="file"][name="' . $name . '"]');

            $zone = $input->parentElement;
            self::assertNotNull($zone, $path);
            self::assertTrue($zone->hasAttribute('data-file-drop'), $path . ': the input sits in the zone');
            self::assertSame('file-drop', $zone->getAttribute('class'), $path . ': unstyled until enhanced');
            self::assertSame($multiple, $input->hasAttribute('multiple'), $path);
            if ($maxMb !== null) {
                self::assertSame($maxMb, $zone->getAttribute('data-file-drop-max-mb'), $path);
            }

            $strings = json_decode((string) $zone->getAttribute('data-file-drop-strings'), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($strings);
            self::assertIsString($strings['type'] ?? null);
            self::assertSame($typeMessage, str_replace('{name}', 'receipt.heic', $strings['type']), $path);
            self::assertSame(
                $multiple ? 'Drag files here or choose files' : 'Drag a file here or choose one',
                $strings['prompt'],
                $path,
            );
            self::assertSame('{count} files added', $strings['added_other'], $path . ': placeholders left for the script');
        }

        $vehicleForm = Html::document(self::body($browser->get($base . '/edit')));
        foreach (['purchase_attachments[]', 'sale_attachments[]'] as $name) {
            $input = Html::element($vehicleForm, 'input[type="file"][name="' . $name . '"]');
            self::assertTrue($input->parentElement?->hasAttribute('data-file-drop') ?? false, $name);
            self::assertSame('paperwork', $input->getAttribute('data-max-files-group'), $name . ': the shared limit is kept');
        }
    }

    public function testTheDropZoneIsTranslated(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $metric = UnitPreset::Metric;
        $this->createOwner($app, preferences: new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            $metric->distance(),
            $metric->volume(),
            $metric->consumption(),
            'EUR',
        ));
        $browser = $this->browserFor($app, 'owner');
        $golf = $this->vehicle($app);

        $document = Html::document(self::body($browser->get('/vehicles/' . $golf->id . '/fuel/new')));
        $zone = Html::element($document, '[data-file-drop]');
        $strings = json_decode((string) $zone->getAttribute('data-file-drop-strings'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($strings);
        self::assertSame('Dateien hierher ziehen oder auswählen', $strings['prompt']);
        self::assertSame('{count} Dateien hinzugefügt', $strings['added_other']);
    }

    public function testPagesWithoutAFileInputHaveNoZone(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        self::assertStringNotContainsString('data-file-drop', self::body($browser->get('/garage')));
    }
}
