<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scan\Rules;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\OcrTsv;
use Logbook\Tests\Support\ScanFiles;
use Logbook\Tests\Support\ScanTestCase;

/**
 * Reading without AI (spec.md §7.27 *Reading without AI*, Phase 53.4):
 * with no model reading for the user and OCR working, a photo is read by
 * OCR and rules, and a text PDF by its text and rules, into the same
 * forms; nothing is sent to any connection and nothing is logged; every
 * field is marked; *Read it as* maps that kind's own values; the `scan`
 * module and its `ai_scan` alias.
 */
final class ScanWithoutAiTest extends ScanTestCase
{
    private const string FAKE = __DIR__ . '/../../../Fixtures/ocr/fake-tesseract';
    private const array RECEIPT = [
        'SHORELINE FUELS LTD',
        '12 Harbour Road Seaton',
        'Tel 0123 456789',
        'VAT No 123 4567 89',
        '14/10/2026 08:42',
        'Pump 3 UNLEADED',
        '42.18 L @ 142.9p/L',
        'TOTAL GBP 60.28',
        'VAT 20% GBP 10.05',
        'PAID CARD VISA',
        'Nectar points 60',
        'Thank you for your custom',
        'Please call again soon',
    ];

    private string $tsv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsv = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-tsv-');
        $this->tempFiles[] = $this->tsv;
        file_put_contents($this->tsv, OcrTsv::lines(self::RECEIPT, 90.0, ['@' => 40.0, 'L' => 40.0, '142.9p/L' => 40.0]));
        putenv('FAKE_TESSERACT_TSV=' . $this->tsv);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_TESSERACT_TSV');
        putenv('FAKE_TESSERACT_MODE');
        parent::tearDown();
    }

    public function testAUserWithAiOffHasTheirPhotoReadWithoutAiAndNothingSent(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->service($this->app, AiPreferences::class)->set($this->owner->id, false);

        $page = self::body($this->browser->get('/scan'));
        self::assertStringContainsString('data-scan-rules', $page);
        $where = 'Read without AI on this server (text recognition and rules)';
        self::assertStringContainsString($where, html_entity_decode($page));
        self::assertStringNotContainsString('Ollama on the desktop', $page, 'the connection is never named to them');

        [$form, $path] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
        ]));
        $html = self::body($form);

        self::assertSame([], $this->provider->requests, 'no request to any connection');
        $logged = $this->connection($this->app)->fetchAllAssociative('SELECT outcome FROM ai_requests');
        self::assertSame([], $logged, 'no usage-log row');
        self::assertStringContainsString('/fuel/new?scan=', (string) end($path));
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        self::assertSame('2026-10-14T08:42', $values['filled_at']);
        self::assertSame('42.18', $values['volume']);
        self::assertSame('1.429', $values['price']);
        self::assertSame('60.28', $values['total']);
        self::assertSame('', $values['station'] ?? '', 'not one of the install\'s stations: left for the owner (#526)');

        $text = html_entity_decode($html);
        self::assertStringContainsString('Read without AI on this server. Check every field before saving.', $text);
        self::assertStringContainsString('Read without AI, check', $text);
        self::assertStringNotContainsString('From the file, check', $text, 'every field carries the rules mark');
        self::assertStringContainsString('Read without AI from the file:', $text, 'and screen readers hear the same');
        self::assertStringNotContainsString('From the file:', $text);
        $hard = substr_count($text, 'Hard to read, check this.');
        self::assertSame(2, $hard, 'the volume and price were read with low confidence');
        self::assertStringNotContainsString('but the total reads', $text, 'the figures add up');
    }

    public function testALoneTotalIsFilledOnlyWhenReadClearly(): void
    {
        // #525: OCR reads 60.28 as 66,28, so nothing adds up; the total itself reads at 74.
        $receipt = ['SHORELINE FUELS', '14/10/2026 08:42', 'Pump 3 UNLEADED', '42,18 L @ 142.9p/L', 'TOTAL GBP 66,28'];
        file_put_contents($this->tsv, OcrTsv::lines($receipt, 95.0, ['66,28' => 74.0]));
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'AI_ENABLED' => 'false']);
        $golf = ['vehicle_id' => (string) $this->garage['Golf']->id];

        [$form] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', $golf));
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        self::assertSame('', $values['total'] ?? '', 'left for the owner');
        self::assertSame('', $values['volume'] ?? '');
        self::assertSame('2026-10-14T08:42', $values['filled_at'], 'a short receipt is still read');

        // Read clearly, the same lone total is filled.
        file_put_contents($this->tsv, OcrTsv::lines($receipt, 95.0));
        [$form] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', $golf));
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        self::assertSame('66.28', $values['total']);
        self::assertSame('', $values['volume'] ?? '', 'still no set adds up');
    }

    public function testWithNoAiAtAllScanShowsWhenOcrWorks(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'AI_ENABLED' => 'false']);

        self::assertStringContainsString('Scan a receipt or document', self::body($this->browser->get('/log/new')));
        self::assertStringContainsString('data-scan-rules', self::body($this->browser->get('/scan')));
        $modules = self::body($this->browser->get('/settings/modules'));
        self::assertStringContainsString('name="scan"', $modules, 'the module is listed');
        $manifest = json_decode(self::body($this->browser->get('/manifest.webmanifest')), true);
        self::assertIsArray($manifest);
        $shortcuts = $manifest['shortcuts'] ?? null;
        self::assertIsArray($shortcuts);
        self::assertContains(['name' => 'Scan', 'url' => '/scan'], $shortcuts);

        [$form] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
        ]));
        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('Read without AI on this server.', html_entity_decode(self::body($form)));
    }

    public function testWithNoModelAndNoOcrScanIsHidden(): void
    {
        $this->scanApp(['AI_ENABLED' => 'false']);

        self::assertSame(404, $this->browser->get('/scan')->getStatusCode());
        self::assertStringNotContainsString('name="scan"', self::body($this->browser->get('/settings/modules')));
    }

    public function testATextPdfIsReadByRulesWhenNoModelReadsIt(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $tasks = $this->service($this->app, AiTaskRepository::class);
        $tasks->unassign(AiTaskName::ReadDocument);
        $tasks->unassign(AiTaskName::ReadText);

        $pdf = ScanFiles::textPdf([
            'Brightwater Motors Ltd, 14 Quay Road, Exmouth',
            'Invoice INV-20431   Date: 12/09/2026',
            'Vehicle: AB12 CDE   Volkswagen Golf   Mileage: 48,120',
            'Full service',
            'Oil and filter change',
            'Labour 80.00   Parts 73.75',
            'Total due 184.50',
            'Terms: payment is due on collection. Parts carry the manufacturer warranty.',
            'Labour is warranted for 12 months or 12,000 miles, whichever comes first.',
        ]);
        [$form, $path] = $this->land($this->scan($pdf, 'invoice.pdf', 'application/pdf'));
        $html = self::body($form);

        self::assertSame([], $this->provider->requests);
        $golf = $this->garage['Golf']->id;
        self::assertStringContainsString('/vehicles/' . $golf . '/maintenance/new?scan=', (string) end($path));
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        self::assertSame('2026-09-12', $values['performed_on']);
        self::assertSame('184.5', $values['cost']);
        self::assertSame('', $values['vendor'] ?? '', 'the garage is left for the owner (#526)');
        self::assertSame("Full service\nOil and filter change", $values['description']);
        self::assertSame('', $values['title'] ?? '', 'the title is left for the owner');
        self::assertStringContainsString('Copied from the invoice, tidy as needed.', html_entity_decode($html));
    }

    public function testATextPdfOfAUserWithAiOffNeverReachesAModel(): void
    {
        // Models assigned for both tasks, OCR off: only the user's own switch keeps the file here.
        $this->scanApp();
        $this->service($this->app, AiPreferences::class)->set($this->owner->id, false);

        $pdf = ScanFiles::textPdf([
            'Kestrel Autos, Unit 4, Mill Lane, Totnes',
            'Invoice 88   Date 22/08/2026',
            'Reg AB12 CDE   Odometer 47,610',
            'Interim service',
            'Total GBP 129.00',
            'Terms: payment is due on collection. Parts carry the manufacturer warranty.',
            'Labour is warranted for 12 months or 12,000 miles, whichever comes first.',
        ]);
        $response = $this->scan($pdf, 'invoice.pdf', 'application/pdf');

        self::assertSame(404, $response->getStatusCode(), 'no model reads for them and OCR is off: Scan is hidden');
        self::assertSame([], $this->provider->requests);

        // With OCR working, Scan opens to them, and their text PDF is read by rules.
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->service($this->app, AiPreferences::class)->set($this->owner->id, false);
        [$form] = $this->land($this->scan($pdf, 'invoice.pdf', 'application/pdf'));

        self::assertSame([], $this->provider->requests, 'no request to any connection');
        $logged = $this->connection($this->app)->fetchAllAssociative('SELECT outcome FROM ai_requests');
        self::assertSame([], $logged, 'no usage-log row');
        self::assertStringContainsString('Read without AI on this server.', html_entity_decode(self::body($form)));
    }

    public function testReadItAsMapsThatKindsOwnReading(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->service($this->app, AiPreferences::class)->set($this->owner->id, false);
        [$form] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
        ]));
        $token = (string) Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'))['scan'];

        [$asInvoice] = $this->land($this->browser->get(
            '/scan/' . $token . '?as=service_invoice&vehicle=' . $this->garage['Golf']->id,
        ));
        $values = Html::formValues(Html::element(Html::document(self::body($asInvoice)), 'form.form'));
        self::assertSame('2026-10-14', $values['performed_on']);
        self::assertSame('60.28', $values['cost'], "the invoice reader's total");
        self::assertSame([], $this->provider->requests, 'no second read');
    }

    public function testOcrsOwnLimitStillApplies(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'AI_ENABLED' => 'false']);
        $now = new \DateTimeImmutable(self::NOW);
        $busy = $this->service($this->app, AiBusyRepository::class);
        self::assertTrue($busy->acquire($this->owner->id, $now, $now->modify('+10 minutes')));

        [$form] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
        ]));

        self::assertStringContainsString('Still working on your last request.', html_entity_decode(self::body($form)));
        self::assertStringNotContainsString('Read without AI on this server.', html_entity_decode(self::body($form)));
    }

    public function testTheModuleKeepsAnAiScanChoice(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->service($this->app, SettingRepository::class)->save(FeatureToggles::SETTING, ['ai_scan' => false]);
        self::assertFalse($this->service($this->app, FeatureToggles::class)->isEnabled(Feature::Scan));
        self::assertSame(404, $this->browser->get('/scan')->getStatusCode());

        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'FEATURES_AI_SCAN' => 'false']);
        self::assertFalse($this->service($this->app, FeatureToggles::class)->isEnabled(Feature::Scan));
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'FEATURES_AI_SCAN' => 'false', 'FEATURES_SCAN' => 'true']);
        self::assertTrue($this->service($this->app, FeatureToggles::class)->isEnabled(Feature::Scan), 'the new name wins');
    }

    public function testAV5cIsReadAsARegistrationDocumentWithItsPlateOnly(): void
    {
        file_put_contents($this->tsv, OcrTsv::lines([
            'VEHICLE REGISTRATION CERTIFICATE V5C',
            'Document reference number 12345678901',
            'A Registration mark AB12 CDE',
            'B Date of first registration 01 03 2018',
            'D.1 Make VOLKSWAGEN  D.3 Model GOLF',
            'E VIN WVWZZZ1KZAW123456',
            'Keep this document safe. It is not proof of ownership or of who the keeper is.',
            'The registered keeper must tell DVLA about any change of name or address.',
        ], 90.0));
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'AI_ENABLED' => 'false']);

        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));
        $html = html_entity_decode(self::body($page));

        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('AB12 CDE', $html);
        self::assertStringNotContainsString('12345678901', $html);
        $stored = $this->connection($this->app)->fetchOne('SELECT result FROM pending_uploads');
        self::assertIsString($stored);
        self::assertStringNotContainsString('12345678901', $stored, 'the reference is never stored');
        self::assertStringContainsString('"registration"', $stored);
    }
}
