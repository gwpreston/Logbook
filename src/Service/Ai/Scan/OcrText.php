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
    /** A value with digits is flagged only when each word with a digit in its row reads at least this (#514). */
    public const float SURE_DIGITS = 90.0;
    /** A gap wider than this many characters ends a run of a reference's digit groups (#501). */
    private const float SHORT_GAP = 3.0;

    /** OCR's text folded for digits (#508): only O→0 and l, I→1, not S/5 or B/8. The value is never folded (K113). */
    private const array DIGIT_SWAPS = ['o' => '0', 'l' => '1', 'i' => '1'];

    /**
     * @param list<string> $folded the words' text folded (EvidenceMatch::fold), one character per entry
     * @param list<array{int, int, float}> $spans each word's [start, end) in $folded, and its confidence
     * @param list<string> $digits the same characters folded for digits only (DIGIT_SWAPS)
     * @param list<array{words: list<string>, mean: float, digits: list<float>, lost: bool}> $rows each row's
     *        words (lower case, look-alikes folded), mean confidence, the confidence of each word with a digit,
     *        and whether the reference redaction took words from it
     */
    private function __construct(
        public string $text,
        public float $meanConfidence,
        public int $wordCount,
        private array $folded,
        private array $spans,
        private array $digits = [],
        private array $rows = [],
        /** OCR's text carries a V5C's markers (before redaction), #510. */
        private bool $registration = false,
        /** OCR's text looks like a V5C, so the wide redaction applied (#513), or the scan said so (#511). */
        private bool $wide = false,
    ) {
    }

    /**
     * $registration: the scan is of a registration document whatever its
     * text says (*Scan the V5C*, #511), so the wide redaction applies.
     */
    public static function of(OcrResult $result, bool $registration = false): self
    {
        $lines = [];
        $length = 0;
        $number = 0;
        $folded = [];
        $spans = [];
        $digits = [];
        $tokens = [];
        // The V5C rule (punctuation, look-alikes, wrapped rows) only for V5C-like text (#500, #513).
        $wide = $registration || Scrubber::looksLikeRegistration($result->layoutText);
        $lost = [];
        foreach (LayoutText::pages(self::withoutReferences($result->lines, $wide, $lost)) as $p => $rows) {
            if ($p > 0) {
                $lines[] = '';
            }
            foreach ($rows as $row) {
                $line = sprintf('%d| %s', ++$number, Scrubber::ocrText($row['text'], $wide));
                if ($length + mb_strlen($line) + 1 > FilePreparer::MAX_TEXT) {
                    break 2;
                }
                $lines[] = $line;
                $length += mb_strlen($line) + 1;
                $words = [];
                $confidence = 0.0;
                $sure = [];
                $rowLost = false;
                foreach ($row['words'] as $word) {
                    $chars = EvidenceMatch::fold($word->text);
                    $spans[] = [count($folded), count($folded) + count($chars), $word->confidence];
                    array_push($folded, ...$chars);
                    array_push($digits, ...self::digitFold($word->text));
                    array_push($words, ...self::words($word->text));
                    $confidence += $word->confidence;
                    if (preg_match('/[0-9]/', $word->text) === 1) {
                        $sure[] = $word->confidence;
                    }
                    $rowLost = $rowLost || isset($lost[spl_object_id($word)]);
                }
                if ($row['words'] !== []) {
                    $tokens[] = [
                        'words' => $words,
                        'mean' => $confidence / count($row['words']),
                        'digits' => $sure,
                        'lost' => $rowLost,
                    ];
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
            $registration || Scrubber::mentionsRegistration($result->layoutText),
            $wide,
        );
    }

    /**
     * The lines without any word that is part of an 11-digit run (spec.md
     * §7.27 *Reading through OCR*, #500, #501). A row's words are read
     * with single spaces between them, but only across a short gap, so a
     * date beside an odometer in another column is kept (K88). With $wide
     * (V5C-like text) punctuation and look-alikes count, and a run that
     * ends a row joins one starting the next row in the same column, so a
     * reference wrapped over rows is still caught. Scrubber::ocrText still
     * runs on each row's text as well. $lost gets the words kept in a row
     * that lost some (K117).
     *
     * @param list<OcrLine> $lines
     * @param array<int, true> $lost
     * @param-out array<int, true> $lost
     * @return list<OcrLine>
     */
    private static function withoutReferences(array $lines, bool $wide, array &$lost = []): array
    {
        $drop = [];
        $pages = LayoutText::pages($lines);
        foreach ($pages as $rows) {
            $words = [];
            foreach ($rows as $row) {
                array_push($words, ...$row['words']);
            }
            $charWidth = LayoutText::wordsCharWidth($words);
            foreach (self::stretches($rows, $charWidth, $wide) as $stretch) {
                $texts = array_map(static fn (OcrWord $w): string => $w->text, $stretch);
                foreach (Scrubber::referenceWords($texts, $wide) as $i) {
                    $drop[spl_object_id($stretch[$i])] = true;
                }
            }
        }
        if ($drop === []) {
            return $lines;
        }
        foreach ($pages as $rows) {
            foreach ($rows as $row) {
                $ids = array_map(spl_object_id(...), $row['words']);
                if (array_intersect_key(array_flip($ids), $drop) !== []) {
                    foreach ($ids as $id) {
                        $lost[$id] = true;
                    }
                }
            }
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
     * gaps wider than SHORT_GAP characters; and, with $wide, digit groups
     * ending one row joined with those starting the next row in the same
     * column, when together they hold exactly 11 digits (a reference
     * wrapped over two or three rows, not a VAT number above a date).
     *
     * @param list<array{text: string, words: list<OcrWord>}> $rows
     * @return list<non-empty-list<OcrWord>>
     */
    private static function stretches(array $rows, float $charWidth, bool $wide): array
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
            $carried = null;
            $sameColumn = $open !== null && abs($first[0]->box->left - $open['left']) / $charWidth <= self::SHORT_GAP;
            if ($wide && $open !== null && $sameColumn) {
                // The groups starting this row that complete the open run's 11 digits, so a date after them
                // (`901 14/10/2026`) neither hides the wrap nor goes with it (K111).
                [$head, $headDigits, $whole] = self::digitGroups($first, false, 11 - $open['digits']);
                $joined = [...$open['words'], ...$head];
                if ($head !== [] && $open['digits'] + $headDigits === 11) {
                    $stretches[] = $joined;
                    // The rest of the row is read on its own: `14/10/2026` isn't 11 digits with `901` gone.
                    $pieces[0] = array_values(array_filter(
                        $first,
                        static fn (OcrWord $w): bool => !in_array($w, $head, true),
                    ));
                } elseif ($head !== [] && $whole && count($pieces) === 1 && count($head) === count($first)) {
                    // A row of digit groups only: the run may go on below.
                    $carried = ['words' => $joined, 'left' => $open['left'], 'digits' => $open['digits'] + $headDigits];
                }
            }
            $pieces = array_values(array_filter($pieces, static fn (array $p): bool => $p !== []));
            array_push($stretches, ...$pieces);
            if ($pieces === []) {
                $open = $carried;
                continue;
            }

            $last = $pieces[count($pieces) - 1];
            // The groups ending this row, up to 10 digits, so a label or date before them (`Issued 01/03/2018
            // 1234 5678`) doesn't stop a wrap below (K111); none when the row already ends with all 11.
            [$tail, $tailDigits, $complete] = self::digitGroups($last, true, 10);
            // The run's column is where its first digit group starts, not the piece's first word: a label
            // beside the groups (`Document reference number 1234 5678`) mustn't hide a wrap below them.
            $open = $carried ?? ($tail === [] || $complete
                ? null
                : ['words' => $tail, 'left' => $tail[0]->box->left, 'digits' => $tailDigits]);
        }

        return $stretches;
    }

    /**
     * The words of digit groups at the end (or start) of a piece, as many
     * as hold at most $limit digits together, and how many digits they
     * hold. The third value: from the end, whether the groups there make
     * exactly 11 digits (a whole reference on the row); from the start,
     * whether the groups taken are all the piece's digit groups.
     *
     * @param non-empty-list<OcrWord> $piece
     * @return array{list<OcrWord>, int, bool}
     */
    private static function digitGroups(array $piece, bool $fromEnd, int $limit): array
    {
        $words = $fromEnd ? array_reverse($piece) : $piece;
        $found = [];
        $digits = 0;
        $sum = 0;
        $eleven = false;
        $whole = true;
        foreach ($words as $word) {
            if (Scrubber::isDateOrTime($word->text)) {
                // A date or time beside the groups is passed over, neither joined nor a stop (merge review of 53.8).
                continue;
            }
            $count = Scrubber::digitsIn($word->text);
            if ($count === null) {
                break;
            }
            $sum += $count;
            $eleven = $eleven || $sum === 11;
            if ($digits + $count > $limit) {
                $whole = false;
                if (!$fromEnd) {
                    break;
                }
                continue;
            }
            if ($whole) {
                $found[] = $word;
                $digits += $count;
            }
        }

        return [$fromEnd ? array_reverse($found) : $found, $digits, $fromEnd ? $eleven : $whole];
    }

    /**
     * Whether the text mentions any of a V5C's markers (spec.md §7.27
     * *Where it goes*, #510, #513), so a photo bound for an *Internet*
     * connection goes as this text instead. `VSC` too: OCR reads a 5 as an
     * S. True for a *Scan the V5C* scan whatever the text (#511).
     */
    public function mentionsRegistration(): bool
    {
        return $this->registration;
    }

    /**
     * Whether the wide redaction applied: the text looks like a V5C (two
     * different markers, or `V5C` or `Registration Certificate`, #513), or
     * the scan is a *Scan the V5C* one (#511).
     */
    public function looksLikeRegistration(): bool
    {
        return $this->wide;
    }

    /**
     * The text's rows without their numbers, as sent (11-digit runs
     * removed), pages separated by an empty row: what reading without AI
     * reads (Phase 53.4).
     *
     * @return list<string>
     */
    public function rows(): array
    {
        return array_map(
            static fn (string $line): string => (string) preg_replace('/^\d+\| /', '', $line),
            $this->text === '' ? [] : explode("\n", $this->text),
        );
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
            . Extractor::between('Between the markers:', $this->text);
    }

    /**
     * Whether a value a vision model read is on the page (spec.md §7.27
     * *The cross-check*, #508): its evidence (or the value) matches within
     * EvidenceMatch's tolerance and the value's own digits appear, in order
     * and unbroken, in that stretch (only OCR's side folded, K113).
     * Otherwise it is Flagged when OCR read confidently there (the row
     * sharing most of the evidence's words, or the page when none does)
     * and, for a value with digits, read each word with a digit there at
     * SURE_DIGITS or more (#514); else Unchecked. A row the reference
     * redaction took words from is never judged (K117).
     */
    public function check(string $value, string $evidence): CrossCheck
    {
        $needle = $evidence !== '' ? $evidence : $value;
        $want = (string) preg_replace('/[^0-9]/', '', $value);
        $at = EvidenceMatch::find(EvidenceMatch::fold($needle), $this->folded);
        if ($at !== null) {
            $span = self::digitsOf(array_slice($this->digits, $at[0], $at[1] - $at[0]));
            if ($want === '' || str_contains($span, $want)) {
                return CrossCheck::Found;
            }
        }
        $row = $this->rowNear($needle);
        if ($row !== null && $row['lost']) {
            return CrossCheck::Unchecked;
        }
        if (($row['mean'] ?? $this->meanConfidence) < self::HARD_TO_READ) {
            return CrossCheck::Unchecked;
        }
        if ($want !== '') {
            $digits = $row !== null ? $row['digits'] : array_merge([], ...array_column($this->rows, 'digits'));
            if ($digits !== [] && min($digits) < self::SURE_DIGITS) {
                return CrossCheck::Unchecked;
            }
        }

        return CrossCheck::Flagged;
    }

    /**
     * The row sharing most of $words's words, or null when no row shares one.
     *
     * @return array{words: list<string>, mean: float, digits: list<float>, lost: bool}|null
     */
    private function rowNear(string $words): ?array
    {
        $wanted = array_flip(self::words($words));
        $best = 0;
        $near = null;
        foreach ($this->rows as $row) {
            $shared = count(array_intersect_key(array_flip($row['words']), $wanted));
            if ($shared > $best) {
                $best = $shared;
                $near = $row;
            }
        }

        return $near;
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
