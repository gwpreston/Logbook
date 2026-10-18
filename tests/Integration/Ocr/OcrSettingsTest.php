<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ocr;

use Logbook\Tests\Support\AppTestCase;

/**
 * Settings' *Reading text from images* page (spec.md §7.27, Phase 53.1):
 * the engine's status and *Test it* for admins, with or without AI, and a
 * 404 for anyone else. Tesseract is the stand-in script, so no real OCR.
 */
final class OcrSettingsTest extends AppTestCase
{
    private const string FAKE = __DIR__ . '/../../Fixtures/ocr/fake-tesseract';

    protected function tearDown(): void
    {
        putenv('FAKE_TESSERACT_MODE');
        parent::tearDown();
    }

    public function testAdminsSeeTheEngineAndCanTestIt(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
        $admin = $this->signedIn($app);

        $ai = self::body($admin->get('/settings/ai'));
        self::assertStringContainsString('Set up: Tesseract, on this server.', $ai);
        self::assertStringContainsString('/settings/ocr', $ai);

        $page = self::body($admin->get('/settings/ocr'));
        self::assertStringContainsString('Tesseract, on this server', $page);
        self::assertStringContainsString('(chosen automatically)', $page);
        self::assertStringContainsString('data-ocr-available="1"', $page);
        self::assertStringContainsString('tesseract 5.3.0', $page);
        self::assertStringContainsString('deu, eng', $page);
        self::assertStringContainsString('30 seconds a page', $page);
        self::assertStringNotContainsString('data-ocr-text', $page);

        $tested = self::body($admin->post('/settings/ocr'));
        self::assertStringContainsString('data-ocr-test="ok"', $tested);
        $region = 'tabindex="0" role="region" aria-label="The text read from the sample receipt"';
        self::assertStringContainsString($region, $tested);
        self::assertStringNotContainsString('data-ocr-missing', $page);
        self::assertMatchesRegularExpression('/Read in [0-9.]+ s, 92% sure on average\./', $tested);
        self::assertStringContainsString("Total              £48.20", html_entity_decode($tested));
    }

    public function testAFailedTestSaysWhy(): void
    {
        putenv('FAKE_TESSERACT_MODE=fail');
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
        $admin = $this->signedIn($app);

        $tested = self::body($admin->post('/settings/ocr'));

        self::assertStringContainsString('data-ocr-test="failed"', $tested);
        self::assertStringContainsString('The engine failed.', $tested);
    }

    public function testTheTestNeedsACsrfToken(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
        $admin = $this->signedIn($app);

        self::assertNotSame(200, $admin->post('/settings/ocr', [], [], false)->getStatusCode());
    }

    public function testWithoutTesseractItSaysSoAndOffersNoTest(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => 'off']);
        $admin = $this->signedIn($app);

        self::assertStringContainsString('No OCR engine is set up.', self::body($admin->get('/settings/ai')));
        $page = self::body($admin->get('/settings/ocr'));
        self::assertStringContainsString('data-ocr-available="0"', $page);
        self::assertStringContainsString('Not found. Check TESSERACT_BINARY', $page);
        self::assertStringContainsString('data-ocr-missing', $page, 'shown as a warning');
        $warning = '/data-ocr-available="0"><span class="details__flag"><svg class="icon"[^>]*>.*?<\/svg>Not working<\/span><\/dd>/s';
        self::assertMatchesRegularExpression($warning, $page);
        self::assertStringNotContainsString('Read the sample receipt', $page);
        $posted = $admin->post('/settings/ocr');
        self::assertSame(200, $posted->getStatusCode(), 'a post still renders the page');
        self::assertStringNotContainsString('data-ocr-test', self::body($posted));
    }

    public function testABinaryThatCannotRunIsNotWorking(): void
    {
        putenv('FAKE_TESSERACT_MODE=broken');
        try {
            $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
            $admin = $this->signedIn($app);

            // K73: found, but it can't run, so it isn't "Ready" or "Set up".
            self::assertStringContainsString('Set up: Tesseract, on this server, but it doesn', self::body($admin->get('/settings/ai')));
            $page = self::body($admin->get('/settings/ocr'));
            self::assertStringContainsString('data-ocr-available="0"', $page);
            self::assertStringContainsString('Found, but it doesn', $page);
            self::assertStringNotContainsString('Read the sample receipt', $page);
        } finally {
            putenv('FAKE_TESSERACT_MODE');
        }
    }

    public function testOcrEngineOffIsSaid(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE, 'OCR_ENGINE' => 'off']);
        $admin = $this->signedIn($app);

        self::assertStringContainsString('Turned off with OCR_ENGINE=off.', self::body($admin->get('/settings/ai')));
        self::assertStringContainsString('data-ocr-off', self::body($admin->get('/settings/ocr')));
    }

    public function testWithAiOffThePageIsReachedFromInstallation(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE, 'AI_ENABLED' => 'false']);
        $admin = $this->signedIn($app);

        $settings = self::body($admin->get('/settings'));
        self::assertStringContainsString('Reading text from images', $settings);
        self::assertSame(404, $admin->get('/settings/ai')->getStatusCode());
        $page = self::body($admin->get('/settings/ocr'));
        self::assertStringContainsString('data-ocr-available="1"', $page);
        self::assertStringContainsString('/settings#installation', $page);
        self::assertStringContainsString('data-ocr-test="ok"', self::body($admin->post('/settings/ocr')));
    }

    public function testWithAiOnTheInstallationGroupDoesNotRepeatIt(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
        $admin = $this->signedIn($app);

        self::assertStringNotContainsString('Tesseract or PaddleOCR: whether it is set up', self::body($admin->get('/settings')));
    }

    public function testMembersGetANotFound(): void
    {
        $app = $this->createApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->signedIn($app);
        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');

        self::assertSame(404, $member->get('/settings/ocr')->getStatusCode());
        self::assertSame(404, $member->post('/settings/ocr')->getStatusCode());
        self::assertStringNotContainsString('Reading text from images', self::body($member->get('/settings')));
    }
}
