<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use Logbook\Repository\AiBusyRepository;
use Logbook\Service\Ai\Scan\GhostscriptRenderer;
use Logbook\Service\Ai\Scan\PdfRenderer;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\OcrEngineChoice;
use Logbook\Support\Storage\ImageCleaner;
use Psr\Clock\ClockInterface;

/**
 * Turns a photo or scanned PDF into text with positions (spec.md §7.27
 * *Reading text from images*, Phase 53.1): picks the engine (`OCR_ENGINE`),
 * prepares each page, and reads it. A PDF's first three pages are rendered
 * at 300 dpi. Never throws: a failure is an OcrResult with its problem.
 *
 * read() takes the user's lock, shared with AI requests (§7.25 *Limits*),
 * so a scan never runs two heavy jobs at once; readHeld() is for a caller
 * that already holds it, as a scan that goes on to a model will.
 */
final class OcrReader
{
    public const int MAX_PAGES = 3;
    public const int PDF_DPI = 300;
    /** Seconds a lock outlives the longest read before another request may take it. */
    private const int LOCK_GRACE = 30;
    /** Tesseract's language codes for Logbook's locales; English is always added. */
    private const array LANGUAGES = [
        'en' => 'eng', 'de' => 'deu', 'fr' => 'fra', 'es' => 'spa', 'it' => 'ita',
        'nl' => 'nld', 'pl' => 'pol', 'pt' => 'por', 'sv' => 'swe', 'da' => 'dan',
    ];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly TesseractEngine $tesseract,
        private readonly PaddleOcrEngine $paddleOcr,
        private ImagePreparer $preparer,
        private readonly PdfRenderer $renderer,
        private readonly AiBusyRepository $busy,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The same reader with another preparer: the eval compares GD's and
     * Imagick's (`bin/ai-eval.php --ocr`).
     */
    public function withPreparer(ImagePreparer $preparer): self
    {
        $copy = clone $this;
        $copy->preparer = $preparer;

        return $copy;
    }

    /**
     * The engine `OCR_ENGINE` picks, or null when it is off or not set up.
     */
    public function engine(): ?OcrEngine
    {
        $engine = match ($this->settings->ocr->engine) {
            OcrEngineChoice::Off => null,
            OcrEngineChoice::Tesseract => $this->tesseract,
            OcrEngineChoice::PaddleOcr => $this->paddleOcr,
            OcrEngineChoice::Auto => $this->paddleOcr->isAvailable() ? $this->paddleOcr : $this->tesseract,
        };

        return $engine !== null && $engine->isAvailable() ? $engine : null;
    }

    /**
     * Whether an engine is set up (found, or an address given), without
     * running it. See isWorking() before relying on it.
     */
    public function isAvailable(): bool
    {
        return $this->engine() !== null;
    }

    /**
     * Whether the engine is set up and answers its status check: a
     * Tesseract binary that is found but can't run (a missing library)
     * isn't working (K73). Runs Tesseract's `--version` once per request.
     */
    public function isWorking(): bool
    {
        return $this->engine()?->status()->available === true;
    }

    /**
     * What Settings shows: the status of the engine `OCR_ENGINE` picks (for
     * `auto` with neither set up, Tesseract's), or null when it is `off`.
     */
    public function status(): ?OcrEngineStatus
    {
        return match ($this->settings->ocr->engine) {
            OcrEngineChoice::Off => null,
            OcrEngineChoice::Tesseract => $this->tesseract->status(),
            OcrEngineChoice::PaddleOcr => $this->paddleOcr->status(),
            OcrEngineChoice::Auto => ($this->paddleOcr->isAvailable() ? $this->paddleOcr : $this->tesseract)->status(),
        };
    }

    /**
     * Reads under the user's lock; Busy when another request of theirs runs.
     */
    public function read(int $userId, string $path, string $mime, string $locale, bool $prepare = true): OcrResult
    {
        if (!$this->isAvailable()) {
            return OcrResult::failed(OcrProblem::Unavailable);
        }
        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d seconds', $this->longestSeconds() + self::LOCK_GRACE));
        if (!$this->busy->acquire($userId, $now, $expires)) {
            return OcrResult::failed(OcrProblem::Busy);
        }
        try {
            return $this->readHeld($path, $mime, $locale, $prepare);
        } finally {
            $this->busy->release($userId, $expires);
        }
    }

    /**
     * The longest a read can take: a PDF's render, then each page's
     * timeout (K76: the lock must outlast it). A scan going on to a model
     * holds its lock this long plus the model's timeout.
     */
    public function longestSeconds(): int
    {
        return GhostscriptRenderer::TIMEOUT_SECONDS + $this->settings->ocr->timeout * self::MAX_PAGES;
    }

    /**
     * Reads with the lock already held by the caller. $prepare false skips
     * the preparation (the eval's baseline).
     */
    public function readHeld(string $path, string $mime, string $locale, bool $prepare = true): OcrResult
    {
        $engine = $this->engine();
        if ($engine === null) {
            return OcrResult::failed(OcrProblem::Unavailable);
        }
        $pages = $this->pages($path, $mime);
        if ($pages === null) {
            return OcrResult::failed(OcrProblem::Unreadable);
        }
        if ($pages === []) {
            return OcrResult::failed(OcrProblem::TooLarge);
        }

        $languages = self::languages($locale);
        $lines = [];
        $read = null;
        foreach ($pages as $i => $bytes) {
            $image = $prepare ? $this->preparer->prepare($bytes) : self::unprepared($bytes);
            $result = $image === null
                ? OcrResult::failed(self::tooLarge($bytes) ? OcrProblem::TooLarge : OcrProblem::Unreadable)
                : $engine->read($image->png, $languages, PageLayout::of($image->width, $image->height), $i + 1);
            if (!$result->ok()) {
                // A later page that fails leaves the pages already read.
                if ($read === null) {
                    return $result;
                }
                break;
            }
            $read ??= $result;
            array_push($lines, ...$result->lines);
        }

        return OcrResult::read($lines, $read->language, $read->engineVersion);
    }

    private static function tooLarge(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return is_array($info) && $info[0] * $info[1] > ImageCleaner::MAX_PIXELS;
    }

    /**
     * Tesseract codes for the user's locale's language, then English.
     *
     * @return list<string>
     */
    public static function languages(string $locale): array
    {
        $language = strtolower(substr($locale, 0, 2));

        return array_values(array_unique([self::LANGUAGES[$language] ?? 'eng', 'eng']));
    }

    /**
     * The file's page images: the photo itself, or a PDF's first pages
     * rendered at 300 dpi. Null when it isn't an image or PDF; [] when a
     * PDF couldn't be rendered.
     *
     * @return list<string>|null
     */
    private function pages(string $path, string $mime): ?array
    {
        if ($mime === 'application/pdf') {
            return $this->renderer->isAvailable() ? $this->renderer->render($path, self::MAX_PAGES, self::PDF_DPI) : [];
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return is_string($bytes) && $bytes !== '' ? [$bytes] : null;
    }

    /**
     * Only decoded, turned upright and re-encoded: the eval's baseline.
     */
    private static function unprepared(string $bytes): ?PreparedImage
    {
        $scale = 1.0;
        $image = GdImagePreparer::open($bytes, $scale);

        return $image === null ? null : GdImagePreparer::png($image, 0.0, $scale, 'none');
    }
}
