<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Service\Ai\Provider\ImageInput;
use Logbook\Support\Storage\ImageCleaner;

/**
 * Makes a scanned file ready for a model (spec.md §7.27 *Preparing the
 * file*). Photos are already upright and stripped by the upload check
 * (§7.12); what is sent is a downscaled JPEG copy. A PDF with a text layer
 * is read as text (cheaper and more accurate), with 11-digit runs removed
 * before sending; a scanned one has its first pages rendered.
 */
final readonly class FilePreparer
{
    /** Long edge of what is sent to the model. */
    public const int MAX_EDGE = 2000;
    public const int MAX_PAGES = 3;
    /** Characters of text on the first page that make a PDF a text PDF. */
    public const int MIN_TEXT = 200;
    public const int MAX_TEXT = 20000;
    private const int DPI = 150;

    public function __construct(
        private PdfText $pdfText,
        private PdfRenderer $renderer,
    ) {
    }

    public function prepare(string $path, string $mime): PreparedFile
    {
        $look = $this->look($path, $mime);

        return $look->isPicture() ? $this->pictures($path, $mime, $look->pageCount) : $look;
    }

    /**
     * A first look, without rendering anything: a text PDF's text; a
     * scanned PDF with no renderer as NoRenderer; otherwise a picture for
     * the route to read (spec.md §7.27 *Routing*). $registration (a *Scan
     * the V5C* scan, #511) gives a text PDF the wide redaction whatever its
     * markers.
     */
    public function look(string $path, string $mime, bool $registration = false): PreparedFile
    {
        if ($mime !== 'application/pdf') {
            return PreparedFile::picture();
        }
        $read = $this->pdfText->read($path, self::MAX_PAGES);
        $first = $read['pages'][0] ?? '';
        if (mb_strlen($first) >= self::MIN_TEXT) {
            $text = mb_substr(implode("\n\n", $read['pages']), 0, self::MAX_TEXT);

            $wide = $registration || Scrubber::looksLikeRegistration($text);

            return PreparedFile::text(Scrubber::text($text, $wide), $read['count']);
        }

        return $this->renderer->isAvailable()
            ? PreparedFile::picture($read['count'] ?: null)
            : PreparedFile::unreadable(ScanProblem::NoRenderer, $read['count'] ?: null);
    }

    /**
     * The images a vision model is sent: the photo downscaled, or a
     * scanned PDF's first pages rendered.
     */
    public function pictures(string $path, string $mime, ?int $pageCount = null): PreparedFile
    {
        if ($mime !== 'application/pdf') {
            $jpeg = ImageCleaner::downscaledJpeg($path, $mime, self::MAX_EDGE);

            return $jpeg === null
                ? PreparedFile::unreadable(ScanProblem::Unreadable)
                : PreparedFile::images([new ImageInput($jpeg, 'image/jpeg')]);
        }
        $images = [];
        foreach ($this->renderer->render($path, self::MAX_PAGES, self::DPI) as $page) {
            $images[] = new ImageInput($this->downscaled($page), 'image/jpeg');
        }

        return $images === []
            ? PreparedFile::unreadable(ScanProblem::Unreadable, $pageCount)
            : PreparedFile::images($images, $pageCount ?? count($images));
    }

    private function downscaled(string $jpeg): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logbook-page-');
        try {
            file_put_contents($tmp, $jpeg);

            return ImageCleaner::downscaledJpeg($tmp, 'image/jpeg', self::MAX_EDGE) ?? $jpeg;
        } finally {
            @unlink($tmp);
        }
    }
}
