<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Tesseract TSV for the fake Tesseract (`FAKE_TESSERACT_TSV`): rows of
 * words with their confidences, laid out top to bottom, 15 px a character.
 */
final class OcrTsv
{
    /**
     * @param list<list<array{string, float}>> $rows each row's words and confidences
     */
    public static function of(array $rows): string
    {
        $out = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        foreach ($rows as $r => $words) {
            $left = 40;
            foreach ($words as $w => [$text, $confidence]) {
                $width = 15 * mb_strlen($text);
                $out[] = implode("\t", [5, 1, 1, 1, $r + 1, $w + 1, $left, 20 + 40 * $r, $width, 30, $confidence, $text]);
                $left += $width + 15;
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Each line's words at one confidence, with some words' own.
     *
     * @param list<string> $lines
     * @param array<string, float> $confidences word → confidence
     */
    public static function lines(array $lines, float $confidence = 90.0, array $confidences = []): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_map(
                static fn (string $word): array => [$word, $confidences[$word] ?? $confidence],
                preg_split('/\s+/', trim($line)) ?: [],
            );
        }

        return self::of($rows);
    }
}
