<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\PageLayout;
use Logbook\Service\Ocr\TesseractEngine;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tesseract run as a binary (spec.md §7.27, Phase 53.1 task 53.1.4), with a
 * stand-in script so the suite needs no Tesseract: missing, off, timing
 * out, failing and reading are each a clean result, and arguments pass
 * through with no shell.
 */
final class TesseractEngineTest extends TestCase
{
    private const string FAKE = __DIR__ . '/../../../Fixtures/ocr/fake-tesseract';

    private string $args;
    private ?string $dir = null;

    protected function setUp(): void
    {
        $this->args = (string) tempnam(sys_get_temp_dir(), 'logbook-fake-args-');
        putenv('FAKE_TESSERACT_ARGS=' . $this->args);
        putenv('FAKE_TESSERACT_MODE');
    }

    protected function tearDown(): void
    {
        putenv('FAKE_TESSERACT_ARGS');
        putenv('FAKE_TESSERACT_MODE');
        @unlink($this->args);
        if ($this->dir !== null) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private function engine(string $binary, int $timeout = 5): TesseractEngine
    {
        $settings = AppSettings::fromEnv(new Env(['TESSERACT_BINARY' => $binary, 'OCR_TIMEOUT' => (string) $timeout]), '/app');

        return new TesseractEngine($settings, new ProcessRunner());
    }

    /**
     * @return list<string>
     */
    private function arguments(): array
    {
        $lines = explode("\n", (string) file_get_contents($this->args));

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    public function testItReadsAPageIntoLinesAndLayoutText(): void
    {
        $engine = $this->engine(self::FAKE);
        $result = $engine->read('png bytes', ['deu', 'eng', 'fra'], PageLayout::Receipt, 2);

        self::assertTrue($result->ok());
        self::assertSame('deu+eng', $result->language);
        self::assertSame('tesseract 5.3.0', $result->engineVersion);
        self::assertStringContainsString('Total              £48.20', $result->layoutText);
        self::assertSame(2, $result->lines[0]->page);

        $args = $this->arguments();
        self::assertStringEndsWith('/page.png', $args[0]);
        self::assertStringEndsWith('/out', $args[1]);
        self::assertSame(['-l', 'deu+eng', '--psm', '4', 'tsv', 'OMP_THREAD_LIMIT=1'], array_slice($args, 2));
        self::assertSame([], glob(dirname($args[0]) . '/*') ?: [], 'the temporary files are removed');
    }

    public function testStatusGivesTheVersionAndLanguagesWithoutOsd(): void
    {
        $status = $this->engine(self::FAKE)->status();

        self::assertTrue($status->available);
        self::assertSame('tesseract', $status->engine);
        self::assertSame('tesseract 5.3.0', $status->version);
        self::assertSame(['deu', 'eng'], $status->languages);
        self::assertStringEndsWith('fake-tesseract', $status->location);
    }

    public function testEnglishIsUsedWhenNoneOfTheWantedLanguagesIsInstalled(): void
    {
        $result = $this->engine(self::FAKE)->read('png', ['pol'], PageLayout::Page);

        self::assertSame('eng', $result->language);
        self::assertSame(['-l', 'eng', '--psm', '3'], array_slice($this->arguments(), 2, 4));
    }

    public function testMissingOrOffIsUnavailable(): void
    {
        foreach (['/nonexistent/tesseract', 'off', 'logbook-no-such-tesseract'] as $binary) {
            $engine = $this->engine($binary);
            self::assertFalse($engine->isAvailable(), $binary);
            self::assertFalse($engine->status()->available, $binary);
            self::assertSame(OcrProblem::Unavailable, $engine->read('png', ['eng'], PageLayout::Page)->problem, $binary);
        }
    }

    public function testRunningPastTheTimeoutIsATimeout(): void
    {
        putenv('FAKE_TESSERACT_MODE=sleep');
        $started = microtime(true);
        $result = $this->engine(self::FAKE, 1)->read('png', ['eng'], PageLayout::Page);

        self::assertSame(OcrProblem::Timeout, $result->problem);
        self::assertLessThan(4, microtime(true) - $started);
    }

    public function testAnErrorExitOrNoOutputFails(): void
    {
        foreach (['fail', 'empty'] as $mode) {
            putenv('FAKE_TESSERACT_MODE=' . $mode);
            $result = $this->engine(self::FAKE)->read('png', ['eng'], PageLayout::Page);
            self::assertSame(OcrProblem::Failed, $result->problem, $mode);
            self::assertSame('', $result->layoutText, $mode);
        }
    }

    public function testABinaryPathWithSpacesAndQuotesIsRunAsItIs(): void
    {
        $this->dir = sys_get_temp_dir() . "/logbook ocr 'quoted' \"dir\" " . bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(self::FAKE, $this->dir . '/tesseract');
        chmod($this->dir . '/tesseract', 0755);
        copy(dirname(self::FAKE) . '/receipt.tsv', $this->dir . '/receipt.tsv');

        $result = $this->engine($this->dir . '/tesseract')->read('png', ['eng'], PageLayout::Page);

        self::assertTrue($result->ok());
        self::assertSame('eng', $this->arguments()[3]);
    }
}
