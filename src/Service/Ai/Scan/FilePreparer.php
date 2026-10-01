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
        if ($mime === 'application/pdf') {
            return $this->pdf($path);
        }
        $jpeg = ImageCleaner::downscaledJpeg($path, $mime, self::MAX_EDGE);

        return $jpeg === null
            ? PreparedFile::unreadable(ScanProblem::Unreadable)
            : PreparedFile::images([new ImageInput($jpeg, 'image/jpeg')]);
    }

    private function pdf(string $path): PreparedFile
    {
        $read = $this->pdfText->read($path, self::MAX_PAGES);
        $first = $read['pages'][0] ?? '';
        if (mb_strlen($first) >= self::MIN_TEXT) {
            $text = mb_substr(implode("\n\n", $read['pages']), 0, self::MAX_TEXT);

            return PreparedFile::text(Scrubber::text($text), $read['count']);
        }

        if (!$this->renderer->isAvailable()) {
            return PreparedFile::unreadable(ScanProblem::NoRenderer, $read['count'] ?: null);
        }
        $images = [];
        foreach ($this->renderer->render($path, self::MAX_PAGES, self::DPI) as $page) {
            $images[] = new ImageInput($this->downscaled($page), 'image/jpeg');
        }

        return $images === []
            ? PreparedFile::unreadable(ScanProblem::Unreadable, $read['count'] ?: null)
            : PreparedFile::images($images, $read['count'] ?: count($images));
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
