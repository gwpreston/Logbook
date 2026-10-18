<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\EvidenceMatch;
use Logbook\Service\Ai\Scan\FilePreparer;
use Logbook\Service\Ai\Scan\OcrText;
use Logbook\Service\Ai\Scan\Scrubber;
use Logbook\Service\Ocr\OcrBox;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrResult;
use Logbook\Service\Ocr\OcrWord;
use PHPUnit\Framework\TestCase;

/**
 * What a text model is sent from OCR, and how evidence is found in it
 * (spec.md §7.27 *Reading through OCR*, *Checking values*; #497, #498).
 */
final class OcrTextTest extends TestCase
{
    public function testLinesAreNumberedAndMarkedAsOcr(): void
    {
        $text = OcrText::of(self::ocr([['SHORELINE', 'FUELS'], ['TOTAL', '£48.20']]));

        self::assertSame("1| SHORELINE FUELS\n2| TOTAL £48.20", $text->text);
        self::assertStringContainsString('text recognition (OCR)', $text->message());
        self::assertStringContainsString("<<<\n1| SHORELINE FUELS\n2| TOTAL £48.20\n>>>", $text->message());
    }

    public function testPagesAreKeptApartAndNumberingRunsOn(): void
    {
        $lines = [self::line(['Page', 'one'], 0, 1), self::line(['Page', 'two'], 0, 2)];
        $text = OcrText::of(OcrResult::read($lines, 'eng', 'tesseract 5.3.0'));

        self::assertSame("1| Page one\n\n2| Page two", $text->text);
    }

    public function testElevenDigitsAreRemovedBeforeNumbering(): void
    {
        $text = OcrText::of(self::ocr([['Ref', '1234', '5678', '901'], ['Tel', '0123', '456789']]));

        self::assertSame("1| Ref\n2| Tel 0123 456789", $text->text, "the reference's words are dropped");
        self::assertSame('Ref ', Scrubber::ocrText('Ref 1234   5678 901'), 'a gap OCR kept as spaces');
        self::assertSame('Ref 1234    5678 901', Scrubber::ocrText('Ref 1234    5678 901'), 'four spaces is two columns');
    }

    public function testAReferenceIsRemovedHoweverWideTheLayoutKeepsItsGaps(): void
    {
        // Merge review of 53.2: a reference alone on its row, indented, laid
        // out with a 30-space gap; and one split across two engine lines.
        $lines = [];
        for ($r = 0; $r < 3; $r++) {
            $lines[] = self::line(['padding'], $r, 1);
        }
        $at = static fn (string $t, int $x, int $row): OcrWord
            => new OcrWord($t, 90.0, new OcrBox($x, 10 + 30 * $row, 10 * mb_strlen($t), 20));
        $lines[] = new OcrLine([$at('1234', 300, 3), $at('5678', 365, 3), $at('901', 430, 3)], 2);
        $lines[] = new OcrLine([$at('REF', 10, 4), $at('1234', 80, 4)], 3);
        $lines[] = new OcrLine([$at('5678', 145, 4), $at('901', 210, 4), $at('Tel', 300, 4), $at('0123', 340, 4)], 4);

        $text = OcrText::of(OcrResult::read($lines, 'eng', 'tesseract 5.3.0'))->text;

        self::assertDoesNotMatchRegularExpression('/1234|5678|901/', $text);
        self::assertStringContainsString('REF', $text);
        self::assertStringContainsString('Tel 0123', $text, 'the rest of the row stays');
        self::assertSame([1, 2, 3], Scrubber::referenceWords(['Ref', '1234', '5678', '901', 'Tel']));
        self::assertSame([], Scrubber::referenceWords(['Tel', '0123', '456789']), 'ten digits');
    }

    public function testWholeLinesUpToTheLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [str_repeat('x', 60)];
        }
        $text = OcrText::of(self::ocr($rows));

        self::assertLessThanOrEqual(FilePreparer::MAX_TEXT, mb_strlen($text->text));
        self::assertStringEndsWith(str_repeat('x', 60), $text->text, 'never a cut line');
    }

    public function testTooLittleText(): void
    {
        self::assertTrue(OcrText::of(self::ocr([array_fill(0, 29, 'word')]))->isTooLittle(), 'under 30 words');
        self::assertFalse(OcrText::of(self::ocr([array_fill(0, 30, 'word')]))->isTooLittle());
        self::assertTrue(OcrText::of(self::ocr([array_fill(0, 40, 'word')], 39.0))->isTooLittle(), 'under 40 confidence');
    }

    public function testEvidenceIsFoundDespiteOcrSwapsAndGivesItsWordsConfidence(): void
    {
        $text = OcrText::of(self::ocr([['T0TAL', 'GBP', '6O.28'], ['Pump', '3', 'UNLEADED']], 90.0, ['6O.28' => 30.0]));

        self::assertSame(70.0, $text->find('TOTAL GBP 60.28'), 'O and 0 are the same; the words average 70');
        self::assertSame(90.0, $text->find('pump 3 unleaded'), 'case and spaces');
        self::assertNull($text->find('MOONBEAM GARAGE'));
    }

    public function testTheToleranceIsTwoEditsPerTwentyCharacters(): void
    {
        $haystack = EvidenceMatch::fold('Brightwater Motors Ltd Total due £184.50');

        self::assertNotNull(EvidenceMatch::find(EvidenceMatch::fold('Brightwatr Motars'), $haystack), 'two edits in 16');
        self::assertNull(EvidenceMatch::find(EvidenceMatch::fold('Brghtwatr Mtars'), $haystack), 'three edits in 14');
        self::assertNull(EvidenceMatch::find(EvidenceMatch::fold('£185'), $haystack), 'under five characters: exact only');
        self::assertNotNull(EvidenceMatch::find(EvidenceMatch::fold('£184'), $haystack));
        self::assertSame(1, EvidenceMatch::allowed(5));
        self::assertSame(1, EvidenceMatch::allowed(10));
        self::assertSame(2, EvidenceMatch::allowed(16));
        self::assertSame(2, EvidenceMatch::allowed(20));
        self::assertSame(0, EvidenceMatch::allowed(4));
        self::assertNull(EvidenceMatch::find([], $haystack));
    }

    public function testTheMatchSpansTheRightWords(): void
    {
        $haystack = EvidenceMatch::fold('aaa Total due xyz');
        $at = EvidenceMatch::find(EvidenceMatch::fold('Totl due'), $haystack);

        self::assertSame([3, 11], $at, 'from the T of Total to the end of due');
    }

    /**
     * @param list<list<string>> $rows
     * @param array<string, float> $confidences
     */
    private static function ocr(array $rows, float $confidence = 90.0, array $confidences = []): OcrResult
    {
        $lines = [];
        foreach ($rows as $r => $words) {
            $lines[] = self::line($words, $r, 1, $confidence, $confidences);
        }

        return OcrResult::read($lines, 'eng', 'tesseract 5.3.0');
    }

    /**
     * @param list<string> $words
     * @param array<string, float> $confidences
     */
    private static function line(array $words, int $row, int $page, float $confidence = 90.0, array $confidences = []): OcrLine
    {
        $out = [];
        $left = 10;
        foreach ($words as $word) {
            $width = 10 * mb_strlen($word);
            $out[] = new OcrWord($word, $confidences[$word] ?? $confidence, new OcrBox($left, 10 + 30 * $row, $width, 20), $page);
            $left += $width + 10;
        }
        assert($out !== []);

        return new OcrLine($out, 1, $page);
    }
}
