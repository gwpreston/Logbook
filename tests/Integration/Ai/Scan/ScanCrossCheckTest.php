<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\OcrTsv;
use Logbook\Tests\Support\ScanTestCase;

/**
 * OCR as a check on the vision model, and text instead of images (spec.md
 * §7.27 *The cross-check*, *Where it goes*; Phase 53.3, #508–#510): a
 * value the page clearly doesn't hold is kept and marked, OCR unsure or
 * failing changes nothing, *Send text, not images* never sends a picture,
 * and on an *Internet* connection a V5C goes as redacted text.
 */
final class ScanCrossCheckTest extends ScanTestCase
{
    private const string FAKE = __DIR__ . '/../../../Fixtures/ocr/fake-tesseract';
    private const string NOT_ON_PAGE = 'Not found on the page, check this carefully.';
    /** A vision answer edited to invent a total. */
    private const array INVENTED = ['total' => ['value' => 'GBP 68.28', 'evidence' => 'TOTAL GBP 68.28']];
    private const array RECEIPT = [
        'SHORELINE FUELS LTD',
        '12 Harbour Road Seaton',
        'Tel 0123 456789',
        'VAT No 123 4567 89',
        '14/10/2026 08:42',
        'Pump 3 UNLEADED',
        '42.18 L @ 142.9p/L',
        'TOTAL GBP 60.28',
        'PAID CARD VISA',
        'Thank you for your custom',
        'Please call again soon',
    ];
    private const array V5C = [
        'V5C Vehicle Registration Certificate',
        'DVLA Swansea SA99 1BA',
        'Document reference number',
        '1234 5678 901',
        'Registration number AB12 CDE',
        'Make VOLKSWAGEN Model GOLF',
        'Date of first registration 01 03 2018',
        'Vehicle identification number WVWZZZ1KZAW000001',
        'Keep this document safe and do not',
        'use it as proof of ownership',
    ];

    private string $tsv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tsv = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-tsv-');
        $this->tempFiles[] = $this->tsv;
        $this->ocrReads(self::RECEIPT);
        putenv('FAKE_TESSERACT_TSV=' . $this->tsv);
        // A vision model on read_document, and Tesseract (the stand-in).
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_TESSERACT_TSV');
        putenv('FAKE_TESSERACT_MODE');
        parent::tearDown();
    }

    public function testAnInventedTotalIsKeptAndFlagged(): void
    {
        $this->reply($this->fuelReply(self::INVENTED));
        $page = $this->scanReceipt();
        $html = self::body($page);
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        $text = html_entity_decode($html);

        self::assertCount(1, $this->sentImages(0), 'the vision model read the picture');
        self::assertSame('68.28', $values['total'], 'kept: the owner decides');
        self::assertSame(1, substr_count($text, self::NOT_ON_PAGE), 'only the invented total, which the tolerant match accepts');
        $under = '/id="f-total-scan"[^>]*>.*?' . preg_quote(self::NOT_ON_PAGE, '/') . '/s';
        self::assertMatchesRegularExpression($under, $html, 'under its field');
        self::assertMatchesRegularExpression('/name="total"[^>]*aria-describedby="[^"]*f-total-scan/', $html, 'tied to it');
        // The sum check says so too, on all three fields.
        self::assertSame(3, substr_count($text, '42.18 L × 142.9p = £60.28, but the total reads £68.28.'));

        self::assertSame(
            ['scan_found' => 8, 'scan_flagged' => 1, 'scan_unchecked' => 0],
            $this->counts(),
            'counted on its usage-log row',
        );
    }

    public function testRightValuesAreNotFlagged(): void
    {
        $this->reply($this->fuelReply());
        $page = $this->scanReceipt();
        $text = html_entity_decode(self::body($page));

        self::assertStringNotContainsString(self::NOT_ON_PAGE, $text);
        self::assertStringNotContainsString('but the total reads', $text);
        self::assertSame(['scan_found' => 9, 'scan_flagged' => 0, 'scan_unchecked' => 0], $this->counts());
    }

    public function testAValueWhereOcrWasUnsureIsNotFlagged(): void
    {
        $this->ocrReads(self::RECEIPT, ['TOTAL' => 30.0, 'GBP' => 30.0, '60.28' => 30.0]);
        $this->reply($this->fuelReply(self::INVENTED));
        $page = $this->scanReceipt();

        self::assertStringNotContainsString(self::NOT_ON_PAGE, html_entity_decode(self::body($page)), 'absence proves nothing');
        self::assertSame(['scan_found' => 8, 'scan_flagged' => 0, 'scan_unchecked' => 1], $this->counts());
    }

    public function testOcrFailingLeavesTheScanAsItWas(): void
    {
        putenv('FAKE_TESSERACT_MODE=fail');
        $this->reply($this->fuelReply(self::INVENTED));
        $page = $this->scanReceipt();
        $html = self::body($page);
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));

        self::assertSame('68.28', $values['total']);
        self::assertSame('42.18', $values['volume']);
        self::assertStringNotContainsString(self::NOT_ON_PAGE, html_entity_decode($html));
        self::assertSame(['scan_found' => null, 'scan_flagged' => null, 'scan_unchecked' => null], $this->counts());
    }

    public function testSendTextNotImagesNeverSendsAPicture(): void
    {
        $this->sendTextOnly();
        $this->reply($this->fuelReply());
        $this->scanReceipt();

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0), 'asserted at the adapter: no image in the request body');
        self::assertStringContainsString('text recognition (OCR)', $this->sentText(0));
        $task = $this->connection($this->app)->fetchOne("SELECT task FROM ai_requests WHERE outcome = 'ok'");
        self::assertSame('read_document', $task);
    }

    public function testSendTextWithoutOcrReadsNoPhoto(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => 'off']);
        $this->sendTextOnly();
        $this->reply($this->fuelReply());
        $page = $this->scanReceipt();

        self::assertSame([], $this->provider->requests, 'the image is never sent instead');
        self::assertStringContainsString('This connection sends text only', html_entity_decode(self::body($page)));
        $form = html_entity_decode(self::body($this->browser->get('/scan')));
        self::assertStringContainsString('sends text only, so photos and scanned PDFs need text recognition', $form);
    }

    public function testOnTheInternetAV5cPhotoGoesAsText(): void
    {
        $this->onTheInternet();
        $this->ocrReads(self::V5C);
        $this->reply($this->registrationReply());
        $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0), 'its markers were found before anything was sent');
        $sent = $this->sentText(0);
        self::assertStringContainsString('Registration Certificate', $sent);
        self::assertStringNotContainsString('5678', $sent, 'the reference is removed');
    }

    public function testOnTheInternetAV5cWithoutMarkersStillGoesAsAPicture(): void
    {
        // Its markers are all Logbook has to go on before sending (#510).
        $this->onTheInternet();
        $unmarked = static fn (string $l): bool => preg_match('/V5C|DVLA|Document reference|Certificate/', $l) !== 1;
        $lines = array_values(array_filter(self::V5C, $unmarked));
        $this->ocrReads([...$lines, 'Further notes on keeping your details up to date']);
        $this->reply($this->registrationReply());
        $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));

        self::assertCount(1, $this->sentImages(0));
    }

    public function testOnTheInternetAReceiptStillGoesAsAPictureAndIsChecked(): void
    {
        $this->onTheInternet();
        $this->reply($this->fuelReply(self::INVENTED));
        $page = $this->scanReceipt();

        self::assertCount(1, $this->sentImages(0));
        self::assertStringContainsString(self::NOT_ON_PAGE, html_entity_decode(self::body($page)));
        $form = html_entity_decode(self::body($this->browser->get('/scan')));
        self::assertStringContainsString('checked on this server by text recognition', $form);
        self::assertStringContainsString('A registration document (V5C) is sent as text instead', $form);
        self::assertStringNotContainsString('receives it with the picture', $form, "#85's warning is not needed");
    }

    private function scanReceipt(): \Psr\Http\Message\ResponseInterface
    {
        $fields = ['vehicle_id' => (string) $this->garage['Golf']->id];

        return $this->land($this->scan(ExifJpeg::make(40, 20), 'receipt.jpg', 'image/jpeg', $fields))[0];
    }

    /**
     * @param list<string> $lines
     * @param array<string, float> $confidences
     */
    private function ocrReads(array $lines, array $confidences = []): void
    {
        file_put_contents($this->tsv, OcrTsv::lines($lines, 90.0, $confidences));
    }

    private function sendTextOnly(): void
    {
        $this->connection($this->app)
            ->executeStatement('UPDATE ai_connections SET send_text = ?', [true], [ParameterType::BOOLEAN]);
    }

    /**
     * read_document's vision model moved to an acknowledged *Internet* connection.
     */
    private function onTheInternet(): void
    {
        $cloud = $this->cloud($this->app);
        $at = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $connections = $this->service($this->app, AiConnectionRepository::class);
        $connections->acknowledge($cloud, $this->owner->id, 'https://api.openai.com/v1', $at);
        $this->assign($this->app, $cloud, 'gpt-4o', AiTaskName::ReadDocument, [Capability::Json, Capability::Images]);
    }

    /**
     * @return array<string, int|null>
     */
    private function counts(): array
    {
        $row = $this->connection($this->app)->fetchAssociative(
            "SELECT scan_found, scan_flagged, scan_unchecked FROM ai_requests WHERE task = 'read_document' AND outcome = 'ok'",
        );
        self::assertIsArray($row);

        return array_map(static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null, $row);
    }

    /**
     * @param array<string, array{value: string, evidence: string}> $changes
     * @return array<string, mixed>
     */
    private function fuelReply(array $changes = []): array
    {
        return [
            'kind' => 'fuel_receipt',
            'fields' => $changes + [
                'date' => ['value' => '14/10/2026', 'evidence' => '14/10/2026 08:42'],
                'time' => ['value' => '08:42', 'evidence' => '14/10/2026 08:42'],
                'vendor' => ['value' => 'Shoreline Fuels Ltd', 'evidence' => 'SHORELINE FUELS LTD'],
                'grade' => ['value' => 'Unleaded', 'evidence' => 'Pump 3 UNLEADED'],
                'volume' => ['value' => '42.18', 'evidence' => '42.18 L'],
                'volume_unit' => ['value' => 'L', 'evidence' => '42.18 L'],
                'price_per_unit' => ['value' => '142.9p', 'evidence' => '@ 142.9p/L'],
                'total' => ['value' => 'GBP 60.28', 'evidence' => 'TOTAL GBP 60.28'],
                'currency' => ['value' => 'GBP', 'evidence' => 'TOTAL GBP 60.28'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationReply(): array
    {
        return [
            'kind' => 'registration',
            'fields' => [
                'registration' => ['value' => 'AB12 CDE', 'evidence' => 'Registration number AB12 CDE'],
                'first_registration' => ['value' => '01 03 2018', 'evidence' => 'first registration 01 03 2018'],
            ],
        ];
    }
}
