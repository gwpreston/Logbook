<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Service\Ocr\LayoutText;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrWord;
use Logbook\Service\Ocr\OcrResult;

/**
 * What a scan sends a text model from OCR (spec.md §7.27 *Reading through
 * OCR*, Phase 53.2): the layout text, each line numbered, 11-digit runs
 * removed, whole lines up to `read_text`'s 20,000 characters (layout text
 * only, #497). Also what the answer's evidence is checked against: each
 * word's place in the folded text, with its confidence.
 */
final readonly class OcrText
{
    /** Below either, there is too little to read: nothing is sent. */
    public const float MIN_MEAN_CONFIDENCE = 40.0;
    public const int MIN_WORDS = 30;
    /** A value whose evidence words average under this is "Hard to read". */
    public const float HARD_TO_READ = 60.0;

    /**
     * @param list<string> $folded the words' text folded (EvidenceMatch::fold), one character per entry
     * @param list<array{int, int, float}> $spans each word's [start, end) in $folded, and its confidence
     */
    private function __construct(
        public string $text,
        public float $meanConfidence,
        public int $wordCount,
        private array $folded,
        private array $spans,
    ) {
    }

    public static function of(OcrResult $result): self
    {
        $lines = [];
        $length = 0;
        $number = 0;
        $folded = [];
        $spans = [];
        foreach (LayoutText::pages(self::withoutReferences($result->lines)) as $p => $rows) {
            if ($p > 0) {
                $lines[] = '';
            }
            foreach ($rows as $row) {
                $line = sprintf('%d| %s', ++$number, Scrubber::ocrText($row['text']));
                if ($length + mb_strlen($line) + 1 > FilePreparer::MAX_TEXT) {
                    break 2;
                }
                $lines[] = $line;
                $length += mb_strlen($line) + 1;
                foreach ($row['words'] as $word) {
                    $chars = EvidenceMatch::fold($word->text);
                    $spans[] = [count($folded), count($folded) + count($chars), $word->confidence];
                    array_push($folded, ...$chars);
                }
            }
        }

        return new self(implode("\n", $lines), $result->meanConfidence, count($result->words), $folded, $spans);
    }

    /**
     * The lines without any word that is part of an 11-digit run in its
     * row's words, read with single spaces between them: so a V5C's
     * reference is removed however wide the gaps OCR's layout would keep
     * between its groups (merge review of 53.2). Scrubber::ocrText still
     * runs on each row's text as well.
     *
     * @param list<OcrLine> $lines
     * @return list<OcrLine>
     */
    private static function withoutReferences(array $lines): array
    {
        $drop = [];
        foreach (LayoutText::pages($lines) as $rows) {
            foreach ($rows as $row) {
                foreach (Scrubber::referenceWords(array_map(static fn (OcrWord $w): string => $w->text, $row['words'])) as $i) {
                    $drop[spl_object_id($row['words'][$i])] = true;
                }
            }
        }
        if ($drop === []) {
            return $lines;
        }
        $kept = [];
        foreach ($lines as $line) {
            $words = array_values(array_filter($line->words, static fn (OcrWord $w): bool => !isset($drop[spl_object_id($w)])));
            if ($words !== []) {
                $kept[] = new OcrLine($words, $line->block, $line->page);
            }
        }

        return $kept;
    }

    /**
     * Too little to send: a mean confidence under 40, or under 30 words.
     */
    public function isTooLittle(): bool
    {
        return $this->wordCount < self::MIN_WORDS || $this->meanConfidence < self::MIN_MEAN_CONFIDENCE;
    }

    /**
     * The user message: the text, marked as OCR output.
     */
    public function message(): string
    {
        return "The document's text, read by text recognition (OCR) from a photo or scan. OCR can misread\n"
            . "characters (0 and O, 1 and l, 5 and S, 8 and B) and may split or join words. Each line starts\n"
            . "with its number and a bar, which are not part of the document; leave them out of evidence.\n"
            . "Between the markers:\n<<<\n" . $this->text . "\n>>>";
    }

    /**
     * The mean confidence of the words $evidence was found in, or null
     * when it isn't in the text (within EvidenceMatch's tolerance).
     */
    public function find(string $evidence): ?float
    {
        $at = EvidenceMatch::find(EvidenceMatch::fold($evidence), $this->folded);
        if ($at === null) {
            return null;
        }
        [$from, $to] = $at;
        $confidences = [];
        foreach ($this->spans as [$start, $end, $confidence]) {
            if ($start < $to && $end > $from) {
                $confidences[] = $confidence;
            }
        }

        return $confidences === [] ? 100.0 : array_sum($confidences) / count($confidences);
    }
}
