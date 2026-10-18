<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use DateTimeImmutable;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\OcrTsv;
use Logbook\Tests\Support\ScanFiles;
use Logbook\Tests\Support\ScanTestCase;

/**
 * Reading photos with a text model (spec.md §7.27 *Routing*, *Reading
 * through OCR*; Phase 53.2): with no vision model, OCR reads the photo or
 * scan and a text model reads its text, into the same forms; evidence is
 * checked against OCR's text with a tolerant match; words read with low
 * confidence are marked; 11-digit runs never leave; too little text,
 * a broken engine, the lock and timeouts.
 */
final class ScanOcrTest extends ScanTestCase
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
        // OCR misreads the O: the evidence "TOTAL GBP 60.28" still matches.
        'T0TAL GBP 60.28',
        'PAID CARD VISA',
        'Thank you for your custom',
        'Please call again soon',
    ];

    private string $tsv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsv = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-tsv-');
        $this->tempFiles[] = $this->tsv;
        file_put_contents($this->tsv, OcrTsv::lines(self::RECEIPT, 90.0, ['@' => 45.0, '142.9p/L' => 45.0]));
        putenv('FAKE_TESSERACT_TSV=' . $this->tsv);
        // A text-only model on read_document: photos go through OCR.
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE], null, [Capability::Json]);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_TESSERACT_TSV');
        putenv('FAKE_TESSERACT_MODE');
        putenv('FAKE_TESSERACT_ARGS');
        parent::tearDown();
    }

    public function testAPhotoIsReadThroughOcrIntoTheSameForm(): void
    {
        $this->reply($this->fuelReply());
        [$page, $path] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
        ]));
        $html = self::body($page);

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0), 'no picture is sent');
        $sent = $this->sentText(0);
        self::assertStringContainsString('text recognition (OCR)', $sent, 'marked as OCR output');
        self::assertStringContainsString('1| SHORELINE FUELS LTD', $sent, 'each line numbered');
        self::assertStringContainsString('8| T0TAL GBP 60.28', $sent);
        self::assertStringContainsString('leave a field out', strtolower($sent), 'the same system text');

        self::assertStringContainsString('/fuel/new?scan=', (string) end($path));
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        self::assertSame('2026-10-14T08:42', $values['filled_at']);
        self::assertSame('42.18', $values['volume']);
        self::assertSame('1.429', $values['price']);
        self::assertSame('60.28', $values['total'], 'evidence matched despite OCR reading T0TAL');

        $text = html_entity_decode($html);
        self::assertStringContainsString('Hard to read, check this.', $text, 'the price was read with low confidence');
        self::assertSame(1, substr_count($text, 'Hard to read, check this.'), 'only the price');
        self::assertStringContainsString('Not found in the file’s text: “MOONBEAM GARAGE”', $text);
        self::assertSame('', $values['station'] ?? '', 'a value whose evidence is not in the text is dropped');
        self::assertStringContainsString('Read on this server (text recognition), then by qwen2.5vl:7b on Ollama on the desktop.', $text);

        $task = $this->connection($this->app)->fetchOne("SELECT task FROM ai_requests WHERE outcome = 'ok'");
        self::assertSame('read_document', $task, 'run under the text-only read_document task');
    }

    public function testWithoutAReadDocumentModelReadTextReadsIt(): void
    {
        $this->service($this->app, AiTaskRepository::class)->unassign(AiTaskName::ReadDocument);
        $this->reply($this->fuelReply());
        $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg'));

        self::assertCount(1, $this->provider->requests);
        self::assertSame('read_text', $this->connection($this->app)->fetchOne("SELECT task FROM ai_requests WHERE outcome = 'ok'"));
    }

    public function testAVisionModelStaysFirst(): void
    {
        $args = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-args-');
        $this->tempFiles[] = $args;
        unlink($args);
        putenv('FAKE_TESSERACT_ARGS=' . $args);
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->reply($this->fuelReply());
        $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg'));

        self::assertCount(1, $this->sentImages(0), 'the vision model gets the picture');
        self::assertStringNotContainsString('OCR', $this->sentText(0));
        $ran = is_file($args) ? (string) file_get_contents($args) : '';
        self::assertStringNotContainsString('tsv', $ran, 'OCR never ran');
    }

    public function testAScannedPdfIsReadThroughOcr(): void
    {
        $this->reply($this->fuelReply());
        $this->land($this->scan(ScanFiles::scannedPdf(['Receipt']), 'scan.pdf', 'application/pdf'));

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0));
        self::assertStringContainsString('1| SHORELINE FUELS LTD', $this->sentText(0));
    }

    public function testATextPdfStillGoesToReadText(): void
    {
        $this->reply($this->fuelReply());
        $text = ScanFiles::textPdf([str_repeat('SHORELINE FUELS 42.18 L TOTAL GBP 60.28 ', 8)]);
        $this->land($this->scan($text, 'receipt.pdf', 'application/pdf'));

        self::assertStringNotContainsString('OCR', $this->sentText(0));
        self::assertSame('read_text', $this->connection($this->app)->fetchOne("SELECT task FROM ai_requests WHERE outcome = 'ok'"));
    }

    public function testElevenDigitRunsNeverLeaveEvenSplitBySpaces(): void
    {
        file_put_contents($this->tsv, OcrTsv::lines([
            ...self::RECEIPT,
            'Document reference 1234 5678 901',
            'Ref 12345 678901',
        ]));
        $this->reply(['kind' => 'registration', 'fields' => []]);
        $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));

        $sent = $this->sentText(0);
        self::assertStringContainsString('Document reference', $sent);
        self::assertStringNotContainsString('1234 5678 901', $sent);
        self::assertStringNotContainsString('678901', $sent);
        self::assertStringContainsString('0123 456789', $sent, 'a run of other lengths is left');
    }

    public function testTooLittleTextSendsNothing(): void
    {
        file_put_contents($this->tsv, OcrTsv::lines(['TOTAL 60.28', 'Thank you']));
        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'blurry.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'fuel',
        ]));

        self::assertSame([], $this->provider->requests);
        $text = html_entity_decode(self::body($page));
        self::assertStringContainsString('Couldn’t make out the text in this photo. Try a flatter, brighter photo.', $text);
        self::assertStringContainsString('blurry.jpg will be attached.', $text);

        file_put_contents($this->tsv, OcrTsv::lines(self::RECEIPT, 35.0));
        $this->land($this->scan(ExifJpeg::make(40, 20), 'dark.jpg', 'image/jpeg'));
        self::assertSame([], $this->provider->requests, 'a mean confidence under 40 sends nothing either');
    }

    public function testAnEngineThatCannotRunMeansPhotosCannotBeRead(): void
    {
        putenv('FAKE_TESSERACT_MODE=broken');
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE], null, [Capability::Json]);
        $form = html_entity_decode(self::body($this->browser->get('/scan')));
        self::assertStringContainsString('Photos and scanned PDFs need a model that reads images, or text recognition', $form);

        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'fuel',
        ]));
        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('needs a model that reads images, or text recognition', html_entity_decode(self::body($page)));
    }

    public function testTheScanPageSaysPhotosAreReadHereFirst(): void
    {
        $form = html_entity_decode(self::body($this->browser->get('/scan')));

        self::assertStringContainsString('data-scan-ocr', $form);
        self::assertStringContainsString('read on this server first (text recognition), and only their text goes to Ollama on the desktop', $form);
        self::assertStringContainsString('On a phone this opens the camera.', $form, 'photos are offered');
    }

    public function testTheTaskPickerSaysPhotosAreReadWithTextRecognition(): void
    {
        $page = self::body($this->browser->get('/settings/ai'));

        self::assertStringContainsString('<span data-ai-task-ocr>Photos are read with text recognition first.</span>', $page);

        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        self::assertStringNotContainsString('data-ai-task-ocr', self::body($this->browser->get('/settings/ai')), 'not for a vision model');
    }

    public function testTheLockCoversOcrAndTheModel(): void
    {
        $busy = $this->service($this->app, AiBusyRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        self::assertTrue($busy->acquire($this->owner->id, $now, $now->modify('+10 minutes')));

        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'fuel',
        ]));
        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('Still working on your last request.', html_entity_decode(self::body($page)));
    }

    public function testAnOcrTimeoutIsOneTimeoutAndNoModelCall(): void
    {
        putenv('FAKE_TESSERACT_MODE=sleep');
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE, 'OCR_TIMEOUT' => '1'], null, [Capability::Json]);
        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'fuel',
        ]));

        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('took too long', html_entity_decode(self::body($page)));
        $logged = $this->connection($this->app)->fetchAllAssociative('SELECT outcome FROM ai_requests');
        self::assertSame([], $logged, 'no model request was made or logged');
    }

    /**
     * @return array<string, mixed>
     */
    private function fuelReply(): array
    {
        return [
            'kind' => 'fuel_receipt',
            'fields' => [
                'date' => ['value' => '14/10/2026', 'evidence' => '14/10/2026 08:42'],
                'time' => ['value' => '08:42', 'evidence' => '14/10/2026 08:42'],
                'vendor' => ['value' => 'Moonbeam Garage', 'evidence' => 'MOONBEAM GARAGE'],
                'grade' => ['value' => 'Unleaded', 'evidence' => 'Pump 3 UNLEADED'],
                'volume' => ['value' => '42.18', 'evidence' => '42.18 L'],
                'volume_unit' => ['value' => 'L', 'evidence' => '42.18 L'],
                'price_per_unit' => ['value' => '142.9p', 'evidence' => '@ 142.9p/L'],
                'total' => ['value' => 'GBP 60.28', 'evidence' => 'TOTAL GBP 60.28'],
            ],
        ];
    }
}
