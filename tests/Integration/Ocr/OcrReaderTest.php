<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ocr;

use DateTimeImmutable;
use Logbook\Repository\AiBusyRepository;
use Logbook\Service\Ocr\GdImagePreparer;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\OcrReader;
use Logbook\Service\Ocr\PaddleOcrEngine;
use Logbook\Service\Ocr\TesseractEngine;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Process\ProcessRunner;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CountingPreparer;
use Logbook\Tests\Support\FakeRenderer;
use Logbook\Tests\Support\ScanFiles;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * OcrReader (spec.md §7.27, Phase 53.1): the engine OCR_ENGINE picks, the
 * lock shared with AI requests, a scanned PDF's pages, and every failure a
 * clean result. Tesseract is the stand-in script.
 */
final class OcrReaderTest extends AppTestCase
{
    private const string FAKE = __DIR__ . '/../../Fixtures/ocr/fake-tesseract';

    private string $photo;
    private FakeRenderer $renderer;
    private int $user = 0;

    protected function setUp(): void
    {
        $this->photo = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-photo-');
        file_put_contents($this->photo, ScanFiles::photo(['TOTAL 48.20']));
        $this->renderer = new FakeRenderer();
    }

    protected function tearDown(): void
    {
        @unlink($this->photo);
        putenv('FAKE_TESSERACT_MODE');
        parent::tearDown();
    }

    /**
     * @param array<string, string> $env
     * @param-out AiBusyRepository $busy
     */
    private function reader(array $env, ?AiBusyRepository &$busy = null): OcrReader
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->user = $this->createOwner($app)->id;
        $busy = $this->service($app, AiBusyRepository::class);
        $settings = AppSettings::fromEnv(new Env($env + ['TESSERACT_BINARY' => self::FAKE]), '/app');

        return new OcrReader(
            $settings,
            new TesseractEngine($settings, new ProcessRunner()),
            new PaddleOcrEngine($settings, new MockHttpClient()),
            new GdImagePreparer(),
            $this->renderer,
            $busy,
            $this->service($app, ClockInterface::class),
        );
    }

    public function testAPhotoIsPreparedAndRead(): void
    {
        $reader = $this->reader([]);

        $result = $reader->read($this->user, $this->photo, 'image/jpeg', 'de_DE');

        self::assertTrue($result->ok());
        self::assertSame('deu+eng', $result->language);
        self::assertStringContainsString('£48.20', $result->layoutText);
        self::assertSame('tesseract', $reader->engine()?->name());
        self::assertSame('tesseract 5.3.0', $reader->status()?->version);
    }

    public function testTheLockIsSharedWithAiRequestsAndReleased(): void
    {
        $reader = $this->reader([], $busy);
        $now = new DateTimeImmutable();
        self::assertTrue($busy->acquire($this->user, $now, $now->modify('+5 minutes')));

        self::assertSame(OcrProblem::Busy, $reader->read($this->user, $this->photo, 'image/jpeg', 'en')->problem);
        $held = $reader->readHeld($this->photo, 'image/jpeg', 'en');
        self::assertTrue($held->ok(), 'a holder reads without taking it again');

        $busy->release($this->user);
        self::assertTrue($reader->read($this->user, $this->photo, 'image/jpeg', 'en')->ok());
        self::assertTrue($busy->acquire($this->user, $now, $now->modify('+5 minutes')), 'released after reading');
    }

    public function testTheLockIsReleasedWhenTheEngineFails(): void
    {
        putenv('FAKE_TESSERACT_MODE=fail');
        $reader = $this->reader([], $busy);

        self::assertSame(OcrProblem::Failed, $reader->read($this->user, $this->photo, 'image/jpeg', 'en')->problem);
        $now = new DateTimeImmutable();
        self::assertTrue($busy->acquire($this->user, $now, $now->modify('+1 minute')));
    }

    public function testAScannedPdfsPagesAreRenderedAt300DpiAndReadInOrder(): void
    {
        $reader = $this->reader([]);
        $pdf = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-pdf-');
        file_put_contents($pdf, ScanFiles::scannedPdf(['A scanned page']));
        try {
            $result = $reader->read($this->user, $pdf, 'application/pdf', 'en');
        } finally {
            @unlink($pdf);
        }

        self::assertTrue($result->ok());
        self::assertSame(1, $this->renderer->calls);
        self::assertSame(1, $result->lines[0]->page);
    }

    public function testTesseractStartsEveryPageBeforeTheResultIsAskedFor(): void
    {
        $preparer = new CountingPreparer();
        $reader = $this->reader([])->withPreparer($preparer);
        $this->renderer->pages = 3;

        $job = $reader->begin($this->photo, 'application/pdf', 'en');

        self::assertSame(3, $preparer->calls, 'all three pages prepared and started with the first');
        $result = $job->result();
        self::assertTrue($result->ok());
        self::assertSame(3, $preparer->calls);
        $pages = array_map(static fn (OcrLine $l): int => $l->page, $result->lines);
        self::assertSame([1, 2, 3], array_values(array_unique($pages)));
    }

    public function testPaddleOcrPreparesOnlyTheFirstPageBeforeTheRequest(): void
    {
        $preparer = new CountingPreparer();
        $reader = $this->reader(['OCR_URL' => 'http://paddleocr:8080'])->withPreparer($preparer);
        $this->renderer->pages = 3;

        $job = $reader->begin($this->photo, 'application/pdf', 'en');

        self::assertSame('paddleocr', $reader->engine()?->name());
        self::assertSame(1, $preparer->calls, 'the others wait: preparing them would only delay the request');
        unset($job);
    }

    public function testAFirstPageThatCannotBePreparedStopsTheOthersStarting(): void
    {
        $preparer = new CountingPreparer(false);
        $reader = $this->reader([])->withPreparer($preparer);
        $this->renderer->pages = 3;

        $job = $reader->begin($this->photo, 'application/pdf', 'en');

        self::assertSame(1, $preparer->calls);
        self::assertSame(OcrProblem::Unreadable, $job->result()->problem);
    }

    public function testAPdfWithNoRendererCannotBeRead(): void
    {
        $reader = $this->reader([]);
        $this->renderer->available = false;

        self::assertSame(OcrProblem::TooLarge, $reader->read($this->user, $this->photo, 'application/pdf', 'en')->problem);
    }

    public function testAFileThatIsNotAPictureIsUnreadable(): void
    {
        $reader = $this->reader([]);

        self::assertSame(OcrProblem::Unreadable, $reader->read($this->user, $this->photo, 'text/plain', 'en')->problem);
        $missing = $reader->read($this->user, '/nonexistent/photo.jpg', 'image/jpeg', 'en');
        self::assertSame(OcrProblem::Unreadable, $missing->problem);
        $bad = (string) tempnam(sys_get_temp_dir(), 'logbook-ocr-bad-');
        file_put_contents($bad, 'not really a jpeg');
        try {
            self::assertSame(OcrProblem::Unreadable, $reader->read($this->user, $bad, 'image/jpeg', 'en')->problem);
        } finally {
            @unlink($bad);
        }
    }

    public function testWithoutPreparationThePictureIsOnlyReEncoded(): void
    {
        $reader = $this->reader([]);

        self::assertTrue($reader->readHeld($this->photo, 'image/jpeg', 'en', false)->ok());
    }

    public function testTheEngineFollowsOcrEngine(): void
    {
        self::assertNull($this->reader(['OCR_ENGINE' => 'off'])->engine());
        self::assertNull($this->reader(['OCR_ENGINE' => 'off'])->status());
        $off = $this->reader(['OCR_ENGINE' => 'off']);
        self::assertSame(OcrProblem::Unavailable, $off->read($this->user, $this->photo, 'image/jpeg', 'en')->problem);
        self::assertSame(OcrProblem::Unavailable, $off->readHeld($this->photo, 'image/jpeg', 'en')->problem);

        $paddle = $this->reader(['OCR_URL' => 'http://paddleocr:8080']);
        self::assertSame('paddleocr', $paddle->engine()?->name(), 'auto prefers PaddleOCR when OCR_URL is set');
        self::assertSame('paddleocr', $paddle->status()?->engine);
        $chosen = $this->reader(['OCR_URL' => 'http://paddleocr:8080', 'OCR_ENGINE' => 'tesseract']);
        self::assertSame('tesseract', $chosen->engine()?->name());

        $none = $this->reader(['OCR_ENGINE' => 'paddleocr']);
        self::assertNull($none->engine(), 'no silent fallback to Tesseract');
        self::assertSame('paddleocr', $none->status()?->engine);
        self::assertFalse($none->isAvailable());
    }

    public function testLanguagesAreTheLocalesThenEnglish(): void
    {
        self::assertSame(['deu', 'eng'], OcrReader::languages('de_DE'));
        self::assertSame(['eng'], OcrReader::languages('en_GB'));
        self::assertSame(['eng'], OcrReader::languages('xx'));
        self::assertSame(['fra', 'eng'], OcrReader::languages('fr'));
    }
}
