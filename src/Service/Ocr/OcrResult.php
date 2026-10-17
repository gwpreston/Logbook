<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * What OCR read from a photo or scanned PDF (spec.md §7.27 *Reading text
 * from images*): its words and lines with positions and confidences, and a
 * layout text that keeps a row's columns together. Never stored: it lives
 * for one request, like the prepared file. With no text, $problem says why.
 */
final readonly class OcrResult
{
    /**
     * @param list<OcrWord> $words
     * @param list<OcrLine> $lines
     */
    private function __construct(
        public array $words,
        public array $lines,
        public string $layoutText,
        /** Mean word confidence, 0–100; 0 with no words. */
        public float $meanConfidence,
        /** The languages asked for, as the engine names them (`deu+eng`). */
        public string $language,
        /** `tesseract 5.3.0`, `paddleocr`; '' when no engine ran. */
        public string $engineVersion,
        public ?OcrProblem $problem = null,
    ) {
    }

    /**
     * @param list<OcrLine> $lines
     */
    public static function read(array $lines, string $language, string $engineVersion): self
    {
        $words = [];
        foreach ($lines as $line) {
            array_push($words, ...$line->words);
        }
        $mean = $words === []
            ? 0.0
            : array_sum(array_map(static fn (OcrWord $w): float => $w->confidence, $words)) / count($words);

        return new self($words, $lines, LayoutText::build($lines), round($mean, 1), $language, $engineVersion);
    }

    public static function failed(OcrProblem $problem, string $language = '', string $engineVersion = ''): self
    {
        return new self([], [], '', 0.0, $language, $engineVersion, $problem);
    }

    public function ok(): bool
    {
        return $this->problem === null;
    }

    /**
     * The lines grouped by the engine's blocks, in reading order.
     *
     * @return list<list<OcrLine>>
     */
    public function blocks(): array
    {
        $blocks = [];
        foreach ($this->lines as $line) {
            $blocks[$line->page . ':' . $line->block][] = $line;
        }

        return array_values($blocks);
    }
}
