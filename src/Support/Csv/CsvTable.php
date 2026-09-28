<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

/**
 * A CSV download: its file name, header and rows.
 */
final readonly class CsvTable
{
    /**
     * @param list<string> $header
     * @param list<list<string|null>> $rows
     */
    public function __construct(
        /** ASCII file name ending in .csv. */
        public string $filename,
        public array $header,
        public array $rows,
    ) {
    }

    public function toCsv(): string
    {
        return CsvWriter::document($this->header, $this->rows);
    }

    /**
     * A file-name-safe fragment: "Pat's Golf GTI" → "pat-s-golf-gti".
     */
    public static function slug(string $text, string $fallback): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', is_string($ascii) ? $ascii : strtolower($text)), '-');

        return $slug !== '' ? substr($slug, 0, 60) : $fallback;
    }
}
