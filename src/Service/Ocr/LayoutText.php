<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * The layout text of an OCR result (spec.md §7.27): its lines in reading
 * order, with lines that sit side by side on the page put on one row and
 * wide gaps kept as runs of spaces, so a receipt's "Total" and "£48.20" stay
 * on one line however the engine split them into blocks. Works from boxes
 * only, so every engine shares it. Pages are separated by a blank line.
 */
final class LayoutText
{
    /** Two lines share a row when they overlap by this share of the shorter one's height. */
    private const float SAME_ROW = 0.5;
    /** A gap wider than this many characters is kept as a column. */
    private const float COLUMN_GAP = 2.0;
    /** Never pad a row wider than this. */
    private const int MAX_WIDTH = 160;

    /**
     * @param list<OcrLine> $lines
     */
    public static function build(array $lines): string
    {
        $pages = [];
        foreach ($lines as $line) {
            $pages[$line->page][] = $line;
        }
        ksort($pages);

        $out = [];
        foreach ($pages as $pageLines) {
            $out[] = self::page($pageLines);
        }

        return implode("\n\n", $out);
    }

    /**
     * @param non-empty-list<OcrLine> $lines
     */
    private static function page(array $lines): string
    {
        $charWidth = self::charWidth($lines);
        $left = min(array_map(static fn (OcrLine $l): int => $l->box->left, $lines));

        $rows = [];
        foreach (self::rows($lines) as $row) {
            usort($row, static fn (OcrLine $a, OcrLine $b): int => $a->box->left <=> $b->box->left);
            $text = '';
            $end = null;
            foreach ($row as $line) {
                if ($end === null) {
                    $text = $line->text;
                } else {
                    $gap = ($line->box->left - $end) / $charWidth;
                    if ($gap > self::COLUMN_GAP) {
                        $column = (int) round(($line->box->left - $left) / $charWidth);
                        $pad = max(2, min($column, self::MAX_WIDTH) - mb_strlen($text));
                        $text .= str_repeat(' ', $pad) . $line->text;
                    } else {
                        $text .= ' ' . $line->text;
                    }
                }
                $end = $line->box->right();
            }
            $rows[] = $text;
        }

        return implode("\n", $rows);
    }

    /**
     * Lines grouped into rows, top to bottom: a line joins the row whose
     * vertical span it overlaps enough.
     *
     * @param non-empty-list<OcrLine> $lines
     * @return list<list<OcrLine>>
     */
    private static function rows(array $lines): array
    {
        usort($lines, static fn (OcrLine $a, OcrLine $b): int => [$a->box->top, $a->box->left] <=> [$b->box->top, $b->box->left]);

        /** @var list<array{top: int, bottom: int, lines: list<OcrLine>}> $rows */
        $rows = [];
        foreach ($lines as $line) {
            $placed = false;
            foreach ($rows as $i => $row) {
                $overlap = min($row['bottom'], $line->box->bottom()) - max($row['top'], $line->box->top);
                $shorter = max(1, min($row['bottom'] - $row['top'], $line->box->height));
                if ($overlap / $shorter >= self::SAME_ROW) {
                    $rows[$i]['lines'][] = $line;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $rows[] = ['top' => $line->box->top, 'bottom' => $line->box->bottom(), 'lines' => [$line]];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['top'] <=> $b['top']);

        return array_map(static fn (array $r): array => $r['lines'], $rows);
    }

    /**
     * The page's typical character width: the median of each word's width
     * over its length.
     *
     * @param non-empty-list<OcrLine> $lines
     */
    private static function charWidth(array $lines): float
    {
        $widths = [];
        foreach ($lines as $line) {
            foreach ($line->words as $word) {
                $length = mb_strlen($word->text);
                if ($length > 0 && $word->box->width > 0) {
                    $widths[] = $word->box->width / $length;
                }
            }
        }
        if ($widths === []) {
            return 10.0;
        }
        sort($widths);

        return max(1.0, $widths[intdiv(count($widths), 2)]);
    }
}
