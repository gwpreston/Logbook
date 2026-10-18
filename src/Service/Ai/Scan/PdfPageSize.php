<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Keeps a PDF's rendered pages to a sane size (spec.md §7.27 *Preparing
 * the file*, K80): the resolution asked for, or lower so the largest of
 * the first pages stays within $maxEdge pixels on its long edge. A tiny
 * PDF with a 200-inch page is rendered small, not to a 30,000-pixel image.
 */
final class PdfPageSize
{
    public const int MAX_EDGE = 5000;

    public static function dpi(string $path, int $pages, int $dpi, int $maxEdge = self::MAX_EDGE): int
    {
        $inches = self::largestEdgeInches($path, $pages);

        return $inches === null || $inches * $dpi <= $maxEdge ? $dpi : max(1, (int) floor($maxEdge / $inches));
    }

    /**
     * The longest edge, in inches, of the first $pages pages' media boxes;
     * null when the PDF can't be parsed or gives none.
     */
    public static function largestEdgeInches(string $path, int $pages): ?float
    {
        try {
            $config = new Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);
            $document = (new Parser([], $config))->parseFile($path);
            $largest = null;
            foreach (array_slice($document->getPages(), 0, $pages) as $page) {
                $box = $page->getDetails()['MediaBox'] ?? null;
                if (!is_array($box) || count($box) !== 4) {
                    continue;
                }
                $box = array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, array_values($box));
                $edge = max(abs($box[2] - $box[0]), abs($box[3] - $box[1])) / 72;
                $largest = max($largest ?? 0.0, $edge);
            }

            return $largest !== null && $largest > 0 ? $largest : null;
        } catch (Throwable) {
            return null;
        }
    }
}
