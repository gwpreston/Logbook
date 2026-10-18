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
    /** A gap wider than this many characters ends a run of a reference's digit groups (#501). */
    private const float SHORT_GAP = 3.0;

    /** Folded for a value's digits (#508): only O→0 and l, I→1, not S/5 or B/8. */
    private const array DIGIT_SWAPS = ['o' => '0', 'l' => '1', 'i' => '1'];

    /**
     * @param list<string> $folded the words' text folded (EvidenceMatch::fold), one character per entry
     * @param list<array{int, int, float}> $spans each word's [start, end) in $folded, and its confidence
     * @param list<string> $digits the same characters folded for digits only (DIGIT_SWAPS)
     * @param list<array{list<string>, float}> $rows each row's words (lower case, look-alikes folded) and mean confidence
     */
    private function __construct(
        public string $text,
        public float $meanConfidence,
        public int $wordCount,
        private array $folded,
        private array $spans,
        private array $digits = [],
        private array $rows = [],
    ) {
    }

    public static function of(OcrResult $result): self
    {
        $lines = [];
        $length = 0;
        $number = 0;
        $folded = [];
        $spans = [];
        $digits = [];
        $tokens = [];
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
                $words = [];
                $confidence = 0.0;
                foreach ($row['words'] as $word) {
                    $chars = EvidenceMatch::fold($word->text);
                    $spans[] = [count($folded), count($folded) + count($chars), $word->confidence];
                    array_push($folded, ...$chars);
                    array_push($digits, ...self::digitFold($word->text));
                    array_push($words, ...self::words($word->text));
                    $confidence += $word->confidence;
                }
                if ($row['words'] !== []) {
                    $tokens[] = [$words, $confidence / count($row['words'])];
                }
            }
        }

        return new self(
            implode("\n", $lines),
            $result->meanConfidence,
            count($result->words),
            $folded,
            $spans,
            $digits,
            $tokens,
        );
    }

    /**
     * The lines without any word that is part of an 11-digit run (spec.md
     * §7.27 *Reading through OCR*, #500, #501). A row's words are read
     * with single spaces between them, but only across a short gap, so a
     * date beside an odometer in another column is kept (K88); a run that
     * ends a row joins one starting the next row in the same column, so a
     * reference wrapped over rows is still caught. Scrubber::ocrText still
     * runs on each row's text as well.
     *
     * @param list<OcrLine> $lines
     * @return list<OcrLine>
     */
    private static function withoutReferences(array $lines): array
    {
        $drop = [];
        foreach (LayoutText::pages($lines) as $rows) {
            $words = [];
            foreach ($rows as $row) {
                array_push($words, ...$row['words']);
            }
            $charWidth = LayoutText::wordsCharWidth($words);
            foreach (self::stretches($rows, $charWidth) as $stretch) {
                foreach (Scrubber::referenceWords(array_map(static fn (OcrWord $w): string => $w->text, $stretch)) as $i) {
                    $drop[spl_object_id($stretch[$i])] = true;
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
     * The runs of words a reference could be printed in: each row split at
     * gaps wider than SHORT_GAP characters; and digit groups ending one row
     * joined with those starting the next row in the same column, when
     * together they hold exactly 11 digits (a reference wrapped over two
     * or three rows, not a VAT number above a date).
     *
     * @param list<array{text: string, words: list<OcrWord>}> $rows
     * @return list<non-empty-list<OcrWord>>
     */
    private static function stretches(array $rows, float $charWidth): array
    {
        $stretches = [];
        /** @var array{words: non-empty-list<OcrWord>, left: int, digits: int}|null $open */
        $open = null;
        foreach ($rows as $row) {
            $pieces = [];
            $piece = [];
            $end = null;
            foreach ($row['words'] as $word) {
                if ($end !== null && ($word->box->left - $end) / $charWidth > self::SHORT_GAP) {
                    $pieces[] = $piece;
                    $piece = [];
                }
                $piece[] = $word;
                $end = max($end ?? 0, $word->box->right());
            }
            if ($piece === []) {
                $open = null;
                continue;
            }
            $pieces[] = $piece;

            $first = $pieces[0];
            [$head, $headDigits] = self::digitGroups($first, false);
            $carried = null;
            if ($open !== null && $head !== [] && abs($first[0]->box->left - $open['left']) / $charWidth <= self::SHORT_GAP) {
                $digits = $open['digits'] + $headDigits;
                $joined = [...$open['words'], ...$head];
                if ($digits === 11) {
                    $stretches[] = $joined;
                } elseif ($digits < 11 && count($pieces) === 1 && count($head) === count($first)) {
                    // A row of digit groups only: the run may go on below.
                    $carried = ['words' => $joined, 'left' => $open['left'], 'digits' => $digits];
                }
            }
            array_push($stretches, ...$pieces);

            $last = $pieces[count($pieces) - 1];
            [$tail, $tailDigits] = self::digitGroups($last, true);
            $open = $carried ?? ($tail === [] || $tailDigits >= 11
                ? null
                : ['words' => $tail, 'left' => $last[0]->box->left, 'digits' => $tailDigits]);
        }

        return $stretches;
    }

    /**
     * The words of digit groups at the end (or start) of a piece, and how
     * many digits they hold.
     *
     * @param non-empty-list<OcrWord> $piece
     * @return array{list<OcrWord>, int}
     */
    private static function digitGroups(array $piece, bool $fromEnd): array
    {
        $words = $fromEnd ? array_reverse($piece) : $piece;
        $found = [];
        $digits = 0;
        foreach ($words as $word) {
            $count = Scrubber::digitsIn($word->text);
            if ($count === null) {
                break;
            }
            $found[] = $word;
            $digits += $count;
        }

        return [$fromEnd ? array_reverse($found) : $found, $digits];
    }

    /**
     * Whether the text carries a V5C's markers (spec.md §7.27 *Where it
     * goes*, #510), so a photo bound for an *Internet* connection goes as
     * this text instead. `VSC` too: OCR reads a 5 as an S.
     */
    public function looksLikeRegistration(): bool
    {
        return preg_match('/\bV[5S]C\b|registration\s+certificate|\bDVLA\b|document\s+reference/iu', $this->text) === 1;
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
     * Whether a value a vision model read is on the page (spec.md §7.27
     * *The cross-check*, #508): its evidence (or the value) matches within
     * EvidenceMatch's tolerance and the value's digits appear, in order and
     * unbroken, in that stretch. Otherwise it is Flagged when OCR read
     * confidently there (the row sharing most of the evidence's words, or
     * the page when none does), else Unchecked.
     */
    public function check(string $value, string $evidence): CrossCheck
    {
        $needle = $evidence !== '' ? $evidence : $value;
        $at = EvidenceMatch::find(EvidenceMatch::fold($needle), $this->folded);
        if ($at !== null) {
            $want = self::digitsOf(self::digitFold($value));
            $span = self::digitsOf(array_slice($this->digits, $at[0], $at[1] - $at[0]));
            if ($want === '' || str_contains($span, $want)) {
                return CrossCheck::Found;
            }
        }

        return $this->confidenceNear($needle) >= self::HARD_TO_READ ? CrossCheck::Flagged : CrossCheck::Unchecked;
    }

    /**
     * The mean confidence of the row sharing most of $words's words, or of
     * the page when no row shares one.
     */
    private function confidenceNear(string $words): float
    {
        $wanted = array_flip(self::words($words));
        $best = 0;
        $confidence = $this->meanConfidence;
        foreach ($this->rows as [$row, $mean]) {
            $shared = count(array_intersect_key(array_flip($row), $wanted));
            if ($shared > $best) {
                $best = $shared;
                $confidence = $mean;
            }
        }

        return $confidence;
    }

    /**
     * @return list<string>
     */
    private static function digitFold(string $text): array
    {
        $chars = mb_str_split(mb_strtolower((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text)));

        return array_map(static fn (string $c): string => self::DIGIT_SWAPS[$c] ?? $c, $chars);
    }

    /**
     * @param list<string> $chars
     */
    private static function digitsOf(array $chars): string
    {
        return (string) preg_replace('/[^0-9]/', '', implode('', $chars));
    }

    /**
     * A text's words of two characters or more, lower case, look-alikes folded.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $w): string => implode('', EvidenceMatch::fold($w)), $words),
            static fn (string $w): bool => mb_strlen($w) >= 2,
        ));
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
