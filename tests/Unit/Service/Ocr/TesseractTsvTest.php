<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use Logbook\Service\Ocr\OcrBox;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrResult;
use Logbook\Service\Ocr\OcrWord;
use Logbook\Service\Ocr\TesseractTsv;
use PHPUnit\Framework\TestCase;

/**
 * Tesseract's TSV read into words and lines, and the layout text that
 * keeps a row's columns together (spec.md §7.27, Phase 53.1).
 */
final class TesseractTsvTest extends TestCase
{
    private function receipt(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../Fixtures/ocr/receipt.tsv');
    }

    public function testWordRowsBecomeLinesWithBoxesAndConfidences(): void
    {
        $lines = TesseractTsv::parse($this->receipt());

        $texts = array_map(static fn (OcrLine $l): string => $l->text, $lines);
        self::assertSame(['SHELL STATION', 'Unleaded', 'Total', '32.15L', '£48.20'], $texts);
        self::assertSame(93.75, $lines[0]->confidence);
        self::assertEquals(new OcrBox(40, 20, 300, 30), $lines[0]->box);
        self::assertSame(3, $lines[4]->block);
        self::assertSame(1, $lines[4]->page);
        self::assertSame(93.5, $lines[4]->words[0]->confidence);
    }

    public function testEmptyWordsAndNegativeConfidencesAreSkipped(): void
    {
        $words = [];
        foreach (TesseractTsv::parse($this->receipt(), 2) as $line) {
            array_push($words, ...$line->words);
            self::assertSame(2, $line->page);
        }

        self::assertCount(6, $words);
        self::assertSame([], TesseractTsv::parse(''));
        self::assertSame([], TesseractTsv::parse("level\tpage_num\n5\t1\t1"));
    }

    public function testLayoutTextKeepsALabelAndItsAmountOnOneRow(): void
    {
        $result = OcrResult::read(TesseractTsv::parse($this->receipt()), 'eng', 'tesseract 5.3.0');

        self::assertSame(
            "SHELL STATION\nUnleaded           32.15L\nTotal              £48.20",
            $result->layoutText,
        );
        self::assertTrue($result->ok());
        self::assertSame(92.4, $result->meanConfidence);
        self::assertCount(6, $result->words);
        self::assertCount(3, $result->blocks());
        self::assertSame('tesseract 5.3.0', $result->engineVersion);
    }

    public function testPagesAreSeparatedByABlankLineAndCloseWordsBySpaces(): void
    {
        $word = static fn (string $text, int $left, int $top): OcrWord
            => new OcrWord($text, 90.0, new OcrBox($left, $top, 10 * mb_strlen($text), 20), 1);
        $lines = [
            new OcrLine([$word('Two', 0, 0)], 1, 2),
            new OcrLine([$word('One', 0, 0)], 1, 1),
            new OcrLine([$word('near', 45, 2)], 2, 1),
        ];

        self::assertSame("One near\n\nTwo", OcrResult::read($lines, 'eng', 'x')->layoutText);
    }

    public function testAWideGapInsideOneLineIsKeptToo(): void
    {
        $word = static fn (string $text, int $left): OcrWord
            => new OcrWord($text, 90.0, new OcrBox($left, 0, 20 * mb_strlen($text), 30), 1);
        $line = new OcrLine([$word('TOTAL', 50), $word('GBP', 520), $word('48.20', 600)]);

        self::assertSame('TOTAL' . str_repeat(' ', 19) . 'GBP 48.20', OcrResult::read([$line], 'eng', 'x')->layoutText);
    }

    public function testAFailedResultHasNoTextAndSaysWhy(): void
    {
        $result = OcrResult::failed(\Logbook\Service\Ocr\OcrProblem::Timeout, 'eng');

        self::assertFalse($result->ok());
        self::assertSame('', $result->layoutText);
        self::assertSame(0.0, OcrResult::read([], 'eng', 'x')->meanConfidence);
        self::assertSame([], $result->blocks());
    }
}
