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
        preg_match('/id="f-total-scan".*?<\/div>/s', $html, $note);
        self::assertSame(2, substr_count($note[0] ?? '', 'class="field__check"'), 'one reason a line, each marked');
        self::assertMatchesRegularExpression('/name="total"[^>]*aria-describedby="[^"]*f-total-scan/', $html, 'tied to it');
        // The sum check says so too: the sentence once, under the total; the others point to it (#531, K106).
        self::assertSame(1, substr_count($text, '42.18 L × 142.9p = £60.28, but the total reads £68.28.'));
        self::assertSame(2, substr_count($text, 'Doesn’t add up with the total.'));

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

    public function testOnTheInternetACroppedV5cWithItsMarkersIsNeverSentAsAPicture(): void
    {
        // Merge review of 53.3: too few words to read, but OCR saw it is a V5C, so nothing is sent.
        $this->onTheInternet();
        $this->ocrReads(['V5C Vehicle Registration Certificate', 'Document reference number', '1234 5678 901', 'Reg AB12 CDE']);
        $this->reply($this->registrationReply());
        [$page] = $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));

        self::assertSame([], $this->provider->requests, 'neither the picture nor the text');
        self::assertStringContainsString('Couldn’t make out the text in this photo', html_entity_decode(self::body($page)));
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
        self::assertStringContainsString('A registration document (V5C) that text recognition spots is sent as text', $form);
        self::assertStringNotContainsString('receives it with the picture', $form, "#85's long warning is not needed");
        self::assertStringContainsString('still goes to OpenAI as a picture', $form, 'its short form is (#510)');
    }

    public function testOnTheInternetSendingTextWarnsOfAMisreadReference(): void
    {
        // #512: the wide rule is for V5C-like text only, so the page says so.
        $form = html_entity_decode(self::body($this->browser->get('/scan')));
        self::assertStringNotContainsString('data-scan-ocr-registration', $form, 'not on this server');

        $this->onTheInternet();
        $this->sendTextOnly();
        $form = html_entity_decode(self::body($this->browser->get('/scan')));
        self::assertStringContainsString('data-scan-ocr-registration', $form);
        self::assertStringContainsString('a number it misreads or splits can still reach OpenAI', $form);
    }

    public function testAStaleNetworkLocationStillKeepsAV5cPictureBack(): void
    {
        // #530, K112: an acknowledged Internet connection once stored as Network, its host public again.
        $this->onTheInternet();
        $this->connection($this->app)->executeStatement(
            "UPDATE ai_connections SET location = 'network' WHERE base_url = 'https://api.openai.com/v1'",
        );
        $this->ocrReads(self::V5C);
        $this->reply($this->registrationReply());
        $this->land($this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg'));

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0), 'asserted at the adapter: located as Internet before sending');
        self::assertStringNotContainsString('5678', $this->sentText(0));
    }

    public function testScanTheV5cOnTheInternetNeverSendsAPicture(): void
    {
        // #511, #512 (4), K104, K108: OCR misses every marker and misreads the reference.
        $this->onTheInternet();
        $unmarked = static fn (string $l): bool => preg_match('/V5C|DVLA|Document reference|Certificate/', $l) !== 1;
        $lines = array_values(array_filter(self::V5C, $unmarked));
        $misread = 'Docurnent refererce 1234-S678-9O1';
        $lines = array_map(static fn (string $l): string => $l === '1234 5678 901' ? $misread : $l, $lines);
        $this->ocrReads([...$lines, '1234 5678 9O1', 'Further notes on keeping your details up to date']);
        $this->reply($this->registrationReply());
        [$page, $path] = $this->land($this->scanTheV5c('Golf'));

        self::assertCount(1, $this->provider->requests);
        self::assertSame([], $this->sentImages(0), 'asserted at the adapter: no image in the request body');
        $sent = $this->sentText(0);
        self::assertStringNotContainsString('678', $sent, 'the wide rule, without markers');
        self::assertStringContainsString('VOLKSWAGEN', $sent);
        self::assertStringContainsString('/scan/', $path[0] ?? '');
        self::assertStringContainsString('vehicle=' . $this->garage['Golf']->id, implode(' ', $path), 'its own vehicle');
        self::assertSame(200, $page->getStatusCode());
    }

    public function testScanTheV5cOnTheInternetWithoutOcrIsNotSent(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => 'off']);
        $this->onTheInternet();
        $this->reply($this->registrationReply());
        [$page] = $this->land($this->scanTheV5c('Golf'));

        self::assertSame([], $this->provider->requests, 'neither the picture nor any text');
        $text = html_entity_decode(self::body($page));
        self::assertStringContainsString('This scan of a registration document is sent only as text', $text);
        self::assertStringContainsString('Edit the vehicle to enter its details by hand.', $text);
    }

    public function testTheVehiclePageAndItsDocumentsOfferScanTheV5c(): void
    {
        $id = $this->garage['Golf']->id;
        $link = '/scan?vehicle=' . $id . '&amp;for=vehicle';

        self::assertStringContainsString($link, self::body($this->browser->get('/vehicles/' . $id)));
        self::assertStringContainsString($link, self::body($this->browser->get('/vehicles/' . $id . '/documents')));
    }

    public function testScanTheV5cWhenOcrFailsIsNotSent(): void
    {
        $this->onTheInternet();
        putenv('FAKE_TESSERACT_MODE=fail');
        $this->reply($this->registrationReply());
        [$page] = $this->land($this->scanTheV5c('Golf'));

        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('sent only as text', html_entity_decode(self::body($page)));
    }

    public function testScanTheV5cOnTheNetworkGoesAsAPictureAndIsScrubbed(): void
    {
        // Not the Internet: the picture goes as before, and any answer is scrubbed whatever its kind.
        $this->ocrReads(self::V5C);
        $this->reply(['kind' => 'other', 'fields' => ['title' => ['value' => 'Ref 1234-5678-901', 'evidence' => 'V5C']]]);
        $this->land($this->scanTheV5c('Golf'));

        self::assertCount(1, $this->sentImages(0));
        $stored = $this->connection($this->app)->fetchOne('SELECT result FROM pending_uploads');
        self::assertIsString($stored);
        self::assertStringNotContainsString('5678', $stored);
    }

    public function testTheScanPageForAV5cSaysItGoesAsText(): void
    {
        $this->onTheInternet();
        $id = $this->garage['Golf']->id;
        $form = html_entity_decode(self::body($this->browser->get('/scan?vehicle=' . $id . '&for=vehicle')));

        self::assertStringContainsString('Scan the V5C', $form);
        self::assertStringContainsString('data-scan-v5c-text', $form);
        self::assertStringNotContainsString('still goes to OpenAI as a picture', $form, 'no V5C warning for it');
        self::assertStringContainsString('name="vehicle_id" value="' . $id . '"', $form);
    }

    public function testScanTheV5cNeedsAVehicleTheUserCanManage(): void
    {
        $response = $this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg', ['for' => 'vehicle', 'vehicle_id' => '999999']);

        self::assertSame(422, $response->getStatusCode(), 'refused, not read without its target');
        $text = html_entity_decode(self::body($response));
        self::assertStringContainsString('Scan the V5C from the page of a vehicle you can edit', $text);
        self::assertSame([], $this->provider->requests);
    }

    private function scanTheV5c(string $model): \Psr\Http\Message\ResponseInterface
    {
        $fields = ['vehicle_id' => (string) $this->garage[$model]->id, 'for' => 'vehicle'];

        return $this->scan(ExifJpeg::make(40, 20), 'v5c.jpg', 'image/jpeg', $fields);
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
