<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * A PDF's text layer, page by page (`smalot/pdfparser`, pure PHP). A PDF
 * that cannot be parsed (encrypted, broken) has no text, so it is read
 * as a scan.
 */
final class PdfText
{
    /**
     * The text of the first $pages pages; [] when there is none to read.
     *
     * @return array{pages: list<string>, count: int}
     */
    public function read(string $path, int $pages): array
    {
        try {
            $config = new Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);
            $document = (new Parser([], $config))->parseFile($path);
            $all = $document->getPages();
            $texts = [];
            foreach (array_slice($all, 0, $pages) as $page) {
                $texts[] = self::tidy($page->getText());
            }

            return ['pages' => $texts, 'count' => count($all)];
        } catch (Throwable) {
            return ['pages' => [], 'count' => 0];
        }
    }

    private static function tidy(string $text): string
    {
        $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n+/u', "\n", $text);

        return trim($text);
    }
}
