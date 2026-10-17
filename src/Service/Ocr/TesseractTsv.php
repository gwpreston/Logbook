<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * Reads Tesseract's TSV output (spec.md §7.27): one row per page, block,
 * paragraph, line and word, with its box and confidence. Only word rows
 * (level 5) with text are kept; they are grouped into lines by their page,
 * block, paragraph and line numbers.
 */
final class TesseractTsv
{
    private const int WORD = 5;

    /**
     * @return list<OcrLine>
     */
    public static function parse(string $tsv, int $page = 1): array
    {
        $grouped = [];
        foreach (preg_split('/\r?\n/', $tsv) ?: [] as $i => $row) {
            $cells = explode("\t", $row);
            if ($i === 0 || count($cells) < 12 || (int) $cells[0] !== self::WORD) {
                continue;
            }
            $text = trim(implode("\t", array_slice($cells, 11)));
            $confidence = (float) $cells[10];
            if ($text === '' || $confidence < 0) {
                continue;
            }
            $key = sprintf('%d:%d:%d', (int) $cells[2], (int) $cells[3], (int) $cells[4]);
            $grouped[$key]['block'] = (int) $cells[2];
            $grouped[$key]['words'][] = new OcrWord(
                $text,
                min(100.0, $confidence),
                new OcrBox((int) $cells[6], (int) $cells[7], (int) $cells[8], (int) $cells[9]),
                $page,
            );
        }

        $lines = [];
        foreach ($grouped as $group) {
            $lines[] = new OcrLine($group['words'], $group['block'], $page);
        }

        return $lines;
    }
}
